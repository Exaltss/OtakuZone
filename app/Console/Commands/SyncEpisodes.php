<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncEpisodes.php
 * Fungsi : full backfill episode + stream URL dari get_videos, resumable, checkpoint per page
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §5.4, Blueprint_ANIMEKU §3.2, §4, §6
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\Episode;
use App\Models\SyncState;
use App\Services\Animeku\AnimekuClient;
use App\Services\Animeku\ResponseMapper;
use App\Services\Animeku\SeriesResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncEpisodes extends Command
{
    private const JOB = 'sync:episodes';

    protected $signature = 'sync:episodes
                            {--count=100 : Item per page}
                            {--start=0 : Halaman mulai; 0 = resume checkpoint}
                            {--end=0 : Halaman akhir; 0 = otomatis dari count_total}
                            {--limit=0 : Batas total item; 0 = semua}
                            {--reset : Mulai ulang dari halaman 1 dan hapus checkpoint job ini}
                            {--dry-run : Jangan tulis DB}
                            {--no-checkpoint : Jangan tulis sync_state}
                            {--throttle=700 : Override throttle ms}
                            {--jitter=250 : Override jitter ms}
                            {--progress=10 : Cetak progres tiap N page}
                            {--empty-retries=3 : Retry halaman kosong}
                            {--request-retries=4 : Retry error request}';

    protected $description = 'Full backfill episode + stream Animeku ke episodes dengan checkpoint, retry, dan grouping seri';

    public function handle(
        AnimekuClient $client,
        ResponseMapper $mapper,
        SeriesResolver $resolver
    ): int {
        $count = max(1, min(100, (int) $this->option('count')));
        $startOption = max(0, (int) $this->option('start'));
        $endOption = max(0, (int) $this->option('end'));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $noCheckpoint = (bool) $this->option('no-checkpoint');
        $reset = (bool) $this->option('reset');
        $throttle = (int) $this->option('throttle');
        $jitter = (int) $this->option('jitter');
        $progressEvery = max(1, (int) $this->option('progress'));
        $emptyRetries = max(1, (int) $this->option('empty-retries'));
        $requestRetries = max(1, (int) $this->option('request-retries'));

        if ($throttle >= 0) {
            config(['zonaotaku.animeku.throttle_ms' => $throttle]);
        }

        if ($jitter >= 0) {
            config(['zonaotaku.animeku.jitter_ms' => $jitter]);
        }

        if ($reset) {
            SyncState::query()->where('job_key', self::JOB)->delete();
        }

        $state = SyncState::firstOrNew(['job_key' => self::JOB]);

        if ($startOption > 0) {
            $start = $startOption;
            $lastPageOk = $start - 1;
        } elseif ($reset || $limit > 0) {
            $start = 1;
            $lastPageOk = 0;
        } else {
            $start = max(1, (int) $state->last_page_ok + 1);
            $lastPageOk = (int) $state->last_page_ok;
        }

        $knownEnd = $endOption > 0 ? $endOption : null;
        $page = $start;
        $status = 'ok';
        $error = null;
        $startedAt = microtime(true);
        $consecutiveErrors = 0;

        $stats = [
            'items' => 0,
            'anime_new' => 0,
            'anime_existing' => 0,
            'ep_new' => 0,
            'ep_existing' => 0,
            'skipped' => 0,
            'empty_pages' => 0,
            'request_errors' => 0,
        ];

        $emptyPages = [];
        $examples = [];

        while (true) {
            if ($knownEnd !== null && $page > $knownEnd) {
                break;
            }

            if ($limit > 0 && $stats['items'] >= $limit) {
                break;
            }

            $fetched = $this->fetchPage($client, $page, $count, $requestRetries, $emptyRetries);

            if (($fetched['ok'] ?? false) !== true) {
                $consecutiveErrors++;
                $stats['request_errors']++;
                $error = (string) ($fetched['error'] ?? 'request_failed');

                $this->error(sprintf('page=%d request_error=%s', $page, $error));

                if ($consecutiveErrors >= 5) {
                    $status = $stats['items'] > 0 ? 'partial' : 'fail';
                    break;
                }

                $page++;

                continue;
            }

            $consecutiveErrors = 0;

            $items = is_array($fetched['items'] ?? null) ? $fetched['items'] : [];
            $countTotal = (int) ($fetched['count_total'] ?? 0);

            if ($knownEnd === null && $countTotal > 0) {
                $knownEnd = (int) ceil($countTotal / $count);
            }

            if ($items === []) {
                $stats['empty_pages']++;

                if (count($emptyPages) < 100) {
                    $emptyPages[] = $page;
                }

                if ($knownEnd === null) {
                    $status = $stats['items'] > 0 ? 'partial' : 'fail';
                    $error = 'empty_page_without_count_total';
                    break;
                }

                $lastPageOk = $page;

                if (!$dryRun && !$noCheckpoint) {
                    $this->checkpoint($lastPageOk, 'ok', $count, $limit, $start, $knownEnd, $stats, $emptyPages, null);
                }

                if (($page - $start + 1) % $progressEvery === 0) {
                    $this->progressLine($page, $knownEnd, $stats, $startedAt);
                }

                $page++;

                continue;
            }

            if ($dryRun) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    if ($limit > 0 && $stats['items'] >= $limit) {
                        break;
                    }

                    $this->processItem($item, $mapper, $resolver, true, $stats, $examples);
                }
            } else {
                DB::transaction(function () use ($items, $mapper, $resolver, $limit, &$stats, &$examples): void {
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        if ($limit > 0 && $stats['items'] >= $limit) {
                            break;
                        }

                        $this->processItem($item, $mapper, $resolver, false, $stats, $examples);
                    }
                });
            }

            $lastPageOk = $page;

            if (!$dryRun && !$noCheckpoint) {
                $this->checkpoint($lastPageOk, 'ok', $count, $limit, $start, $knownEnd, $stats, $emptyPages, null);
            }

            if (($page - $start + 1) % $progressEvery === 0) {
                $this->progressLine($page, $knownEnd, $stats, $startedAt);
            }

            $page++;
        }

        if (!$dryRun && !$noCheckpoint) {
            $this->checkpoint($lastPageOk, $status, $count, $limit, $start, $knownEnd, $stats, $emptyPages, $error);
        }

        if (!$dryRun) {
            DB::statement('
                UPDATE anime a
                SET a.episodes_total = (
                    SELECT COUNT(*)
                    FROM episodes e
                    WHERE e.anime_id = a.id
                )
            ');
        }

        $this->table(
            ['metric', 'value'],
            [
                ['status', $status],
                ['items', (string) $stats['items']],
                ['anime_new', (string) $stats['anime_new']],
                ['anime_existing', (string) $stats['anime_existing']],
                ['ep_new', (string) $stats['ep_new']],
                ['ep_existing', (string) $stats['ep_existing']],
                ['skipped', (string) $stats['skipped']],
                ['empty_pages', (string) $stats['empty_pages']],
                ['request_errors', (string) $stats['request_errors']],
                ['last_page_ok', (string) $lastPageOk],
                ['known_end_page', $knownEnd === null ? '-' : (string) $knownEnd],
                ['dry_run', $dryRun ? 'true' : 'false'],
                ['checkpoint', $noCheckpoint ? 'skipped' : 'written'],
                ['elapsed_s', (string) (int) round(microtime(true) - $startedAt)],
            ]
        );

        if ($emptyPages !== []) {
            $this->line('Halaman kosong: ' . implode(',', $emptyPages));
        }

        if ($examples !== []) {
            $this->line('Contoh episode:');

            foreach ($examples as $example) {
                $this->line(' - ' . $example);
            }
        }

        if ($error !== null) {
            $this->error('Error: ' . $error);
        }

        return $status === 'ok' || $stats['items'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPage(
        AnimekuClient $client,
        int $page,
        int $count,
        int $requestRetries,
        int $emptyRetries
    ): array {
        $lastError = null;
        $emptyAttempts = 0;

        for ($i = 0; $i < $requestRetries; $i++) {
            try {
                $response = $client->latest($page, $count);
                $items = $response['latest_anime'] ?? $response['data'] ?? [];
                $countTotal = (int) ($response['count_total'] ?? $response['total'] ?? 0);

                if (is_array($items) && $items !== []) {
                    return [
                        'ok' => true,
                        'items' => $items,
                        'count_total' => $countTotal,
                    ];
                }

                $emptyAttempts++;

                if ($emptyAttempts >= $emptyRetries) {
                    return [
                        'ok' => true,
                        'items' => [],
                        'count_total' => $countTotal,
                    ];
                }

                sleep(2);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                sleep(min(30, 2 ** $i));
            }
        }

        return [
            'ok' => false,
            'error' => $lastError ?? 'request_failed',
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,int> $stats
     * @param array<int,string> $examples
     */
    private function processItem(
        array $item,
        ResponseMapper $mapper,
        SeriesResolver $resolver,
        bool $dryRun,
        array &$stats,
        array &$examples
    ): void {
        $stats['items']++;

        $series = $resolver->resolve($item);
        $mapped = $mapper->mapItem($item);
        $quality = $mapped['episode']['quality_urls'] ?? [];
        $episodeExternalId = (string) ($series['episode_external_id'] ?? '');

        if ($episodeExternalId === '') {
            $stats['skipped']++;

            return;
        }

        if (!is_array($quality) || $quality === []) {
            $stats['skipped']++;

            return;
        }

        $anime = Anime::query()->firstOrNew([
            'source' => 'animeku',
            'external_id' => $series['external_id'],
        ]);

        $isNewAnime = !$anime->exists;

        if ($isNewAnime) {
            $anime->slug = $this->uniqueSlug((string) $series['slug'], (string) $series['external_id']);
            $anime->episodes_total = 0;
            $anime->popularity_score = 0;
            $anime->is_hidden = false;
        }

        $anime->title = (string) $series['title'];
        $anime->cat_id = $series['cat_id'];
        $anime->synopsis = $mapped['anime']['synopsis'] ?? null;
        $anime->type = $mapped['anime']['type'] ?? null;
        $anime->status = $mapped['anime']['status'] ?? null;
        $anime->year = $mapped['anime']['year'] ?? null;
        $anime->season = $mapped['anime']['season'] ?? null;
        $anime->rating_avg = $mapped['anime']['rating_avg'] ?? null;
        $anime->view_count = $mapped['anime']['view_count'] ?? null;
        $anime->raw_meta = $mapped['anime']['raw_meta'] ?? null;
        $anime->synced_at = now();

        if (!$dryRun) {
            $anime->save();
        }

        $stats[$isNewAnime ? 'anime_new' : 'anime_existing']++;

        $number = $series['number'];

        if ($number === null) {
            $number = $dryRun ? 0.01 : $this->nextNumber((int) $anime->id);
        } elseif (!$dryRun) {
            $collision = Episode::query()
                ->where('anime_id', $anime->id)
                ->where('number', $number)
                ->where('external_id', '!=', $episodeExternalId)
                ->exists();

            if ($collision) {
                $number = $this->nextNumber((int) $anime->id);
            }
        }

        if ($number === null) {
            $stats['skipped']++;

            return;
        }

        if ($dryRun) {
            $stats['ep_new']++;

            if (count($examples) < 8) {
                $examples[] = sprintf(
                    'DRY %s | ep=%s | %s',
                    Str::limit($series['title'], 55, ''),
                    number_format((float) $number, 2, '.', ''),
                    Str::limit((string) ($item['video_title'] ?? ''), 75, '')
                );
            }

            return;
        }

        $episode = Episode::query()->firstOrNew([
            'anime_id' => $anime->id,
            'number' => $number,
        ]);

        $isNewEpisode = !$episode->exists;

        $episode->external_id = $episodeExternalId;
        $episode->title = (string) ($item['video_title'] ?? 'Episode');
        $episode->thumbnail_path = $episode->thumbnail_path ?? null;
        $episode->duration_sec = $mapped['episode']['duration_sec'] ?? null;
        $episode->quality_urls = $quality;
        $episode->is_hidden = false;
        $episode->synced_at = now();
        $episode->save();

        $stats[$isNewEpisode ? 'ep_new' : 'ep_existing']++;

        if (count($examples) < 8) {
            $examples[] = sprintf(
                'EP %s | ep=%s | %s',
                Str::limit($series['title'], 55, ''),
                number_format((float) $number, 2, '.', ''),
                Str::limit((string) ($item['video_title'] ?? ''), 75, '')
            );
        }
    }

    private function checkpoint(
        int $lastPageOk,
        string $status,
        int $count,
        int $limit,
        int $start,
        ?int $knownEnd,
        array $stats,
        array $emptyPages,
        ?string $error
    ): void {
        SyncState::query()->updateOrCreate(
            ['job_key' => self::JOB],
            [
                'last_page_ok' => $lastPageOk,
                'cursor' => (string) $lastPageOk,
                'last_status' => $status,
                'last_run_at' => now(),
                'meta' => [
                    'mode' => 'episodes-streams',
                    'count' => $count,
                    'limit' => $limit,
                    'start' => $start,
                    'known_end_page' => $knownEnd,
                    'stats' => $stats,
                    'empty_pages' => $emptyPages,
                    'error' => $error,
                ],
            ]
        );
    }

    private function progressLine(int $page, ?int $knownEnd, array $stats, float $startedAt): void
    {
        $elapsed = (int) round(microtime(true) - $startedAt);

        $this->line(sprintf(
            '[%s] page=%d/%s items=%d anime_new=%d anime_exist=%d ep_new=%d ep_exist=%d skipped=%d empty=%d errors=%d elapsed=%ds',
            now()->toDateTimeString(),
            $page,
            $knownEnd === null ? '?' : (string) $knownEnd,
            $stats['items'],
            $stats['anime_new'],
            $stats['anime_existing'],
            $stats['ep_new'],
            $stats['ep_existing'],
            $stats['skipped'],
            $stats['empty_pages'],
            $stats['request_errors'],
            $elapsed
        ));
    }

    private function uniqueSlug(string $slug, string $externalId): string
    {
        $base = Str::limit($slug !== '' ? $slug : 'series', 240, '');
        $candidate = $base;
        $suffix = substr(hash('sha256', $externalId), 0, 6);

        if ($this->slugTakenByOther($candidate, $externalId)) {
            $candidate = Str::limit($base . '-' . $suffix, 260, '');
        }

        $i = 1;

        while ($this->slugTakenByOther($candidate, $externalId) && $i <= 5) {
            $candidate = Str::limit($base . '-' . $suffix . '-' . $i, 260, '');
            $i++;
        }

        if ($this->slugTakenByOther($candidate, $externalId)) {
            $candidate = Str::limit(
                $base . '-' . substr(hash('sha256', $externalId . microtime()), 0, 8),
                260,
                ''
            );
        }

        return $candidate;
    }

    private function slugTakenByOther(string $slug, string $externalId): bool
    {
        return Anime::query()
            ->where('slug', $slug)
            ->where('external_id', '!=', $externalId)
            ->exists();
    }

    private function nextNumber(int $animeId): ?float
    {
        $max = (float) (Episode::query()
            ->where('anime_id', $animeId)
            ->max('number') ?? 0);

        $maxHundred = (int) round($max * 100);

        for ($h = $maxHundred + 1; $h <= 99999999; $h++) {
            $candidate = round($h / 100, 2);

            $exists = Episode::query()
                ->where('anime_id', $animeId)
                ->where('number', $candidate)
                ->exists();

            if (!$exists) {
                return $candidate;
            }
        }

        return null;
    }
}
