<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncFull.php
 * Fungsi : full backfill katalog Animeku secara resumable, append-only, checkpoint per page
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §5.4, §5.6
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

final class SyncFull extends Command
{
    protected $signature = 'sync:full
                            {--pages=0 : Jumlah page; 0 = jalan sampai upstream kosong}
                            {--count=20 : Item per page}
                            {--limit=0 : Batas total item untuk sampling; 0 = tanpa batas}
                            {--start=0 : Override halaman mulai; 0 = checkpoint/reset}
                            {--reset : Mulai ulang dari halaman 1}
                            {--anime-only : Hanya sinkron katalog anime, lewati penulisan episode}
                            {--dry-run : Jangan tulis DB}
                            {--no-checkpoint : Jangan tulis sync_state}
                            {--throttle=-1 : Override throttle ms; -1 pakai config}
                            {--jitter=-1 : Override jitter ms; -1 pakai config}
                            {--sleep=0 : Tidur detik antar page}
                            {--progress=1 : Cetak progres tiap N page}';

    protected $description = 'Full backfill Animeku ke anime + episodes dengan grouping seri, checkpoint per page, mode anime-only, dan progres live';

    public function handle(
        AnimekuClient $client,
        ResponseMapper $mapper,
        SeriesResolver $resolver
    ): int {
        $count = max(1, min(100, (int) $this->option('count')));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $animeOnly = (bool) $this->option('anime-only');
        $reset = (bool) $this->option('reset');
        $noCheckpoint = (bool) $this->option('no-checkpoint');
        $maxPages = max(0, (int) $this->option('pages'));
        $sleep = max(0, (int) $this->option('sleep'));
        $progressEvery = max(1, (int) ($this->option('progress') ?: 1));
        $maxEpisodeNumber = (float) config('zonaotaku.sync.max_episode_number', 999.99);

        $throttle = (int) $this->option('throttle');
        $jitter = (int) $this->option('jitter');

        if ($throttle >= 0) {
            config(['zonaotaku.animeku.throttle_ms' => $throttle]);
        }

        if ($jitter >= 0) {
            config(['zonaotaku.animeku.jitter_ms' => $jitter]);
        }

        $state = SyncState::firstOrNew(['job_key' => 'sync:full']);
        $startOption = (int) $this->option('start');

        if ($startOption > 0) {
            $start = $startOption;
        } elseif ($reset || $limit > 0) {
            $start = 1;
        } else {
            $start = max(1, (int) $state->last_page_ok + 1);
        }

        $page = $start;
        $lastPageOk = $reset ? 0 : (int) $state->last_page_ok;
        $knownLastPage = null;
        $status = 'ok';
        $error = null;
        $startedAt = microtime(true);

        $stats = [
            'items' => 0,
            'series_new' => 0,
            'series_existing' => 0,
            'ep_new' => 0,
            'ep_existing' => 0,
            'skipped' => 0,
            'skipped_overlimit' => 0,
        ];

        $examples = [];

        while (true) {
            if ($maxPages > 0 && $page >= $start + $maxPages) {
                break;
            }

            if ($limit > 0 && $stats['items'] >= $limit) {
                break;
            }

            try {
                $response = $client->latest($page, $count);
            } catch (\Throwable $e) {
                $status = $stats['items'] > 0 ? 'partial' : 'fail';
                $error = $e->getMessage();
                $this->error(sprintf('page=%d error=%s', $page, $error));
                break;
            }

            $items = $response['latest_anime'] ?? $response['data'] ?? [];

            if (!is_array($items)) {
                $status = 'fail';
                $error = 'invalid_payload';
                $this->error(sprintf('page=%d error=%s', $page, $error));
                break;
            }

            if ($knownLastPage === null) {
                $knownLastPage = $this->extractLastPage($response, $count);
            }

            $lastPageAllowed = $maxPages > 0
                ? $start + $maxPages - 1
                : ((int) $knownLastPage > 0 ? $knownLastPage : PHP_INT_MAX);

            if ($items === []) {
                if ($page === $start) {
                    $this->warn('Halaman awal kosong.');
                    $status = $stats['items'] > 0 ? 'partial' : 'fail';
                }

                break;
            }

            if ($dryRun) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    if ($limit > 0 && $stats['items'] >= $limit) {
                        break;
                    }

                    $this->processItem($item, $mapper, $resolver, true, $animeOnly, $maxEpisodeNumber, $stats, $examples);
                }
            } else {
                DB::transaction(function () use ($items, $mapper, $resolver, $limit, $animeOnly, $maxEpisodeNumber, &$stats, &$examples): void {
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        if ($limit > 0 && $stats['items'] >= $limit) {
                            break;
                        }

                        $this->processItem($item, $mapper, $resolver, false, $animeOnly, $maxEpisodeNumber, $stats, $examples);
                    }
                });
            }

            $lastPageOk = $page;

            if (!$dryRun && !$noCheckpoint) {
                $this->checkpoint($lastPageOk, 'ok', $count, $limit, $start, $maxPages, $knownLastPage, $animeOnly, $stats, null);
            }

            if (($page - $start + 1) % $progressEvery === 0) {
                $elapsed = (int) round(microtime(true) - $startedAt);

                $this->line(sprintf(
                    '[%s] page=%d/%s mode=%s items=%d series_new=%d series_exist=%d ep_new=%d ep_exist=%d skipped=%d overlimit=%d elapsed=%ds',
                    now()->toDateTimeString(),
                    $page,
                    (int) $knownLastPage > 0 ? (string) $knownLastPage : '?',
                    $animeOnly ? 'anime-only' : 'full',
                    $stats['items'],
                    $stats['series_new'],
                    $stats['series_existing'],
                    $stats['ep_new'],
                    $stats['ep_existing'],
                    $stats['skipped'],
                    $stats['skipped_overlimit'],
                    $elapsed
                ));
            }

            if ($page >= $lastPageAllowed) {
                break;
            }

            $page++;

            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        if (!$dryRun && !$noCheckpoint) {
            $this->checkpoint($lastPageOk, $status, $count, $limit, $start, $maxPages, $knownLastPage, $animeOnly, $stats, $error);
        }

        $this->table(
            ['metric', 'value'],
            [
                ['status', $status],
                ['mode', $animeOnly ? 'anime-only' : 'full'],
                ['items', (string) $stats['items']],
                ['series_new', (string) $stats['series_new']],
                ['series_existing', (string) $stats['series_existing']],
                ['ep_new', (string) $stats['ep_new']],
                ['ep_existing', (string) $stats['ep_existing']],
                ['skipped', (string) $stats['skipped']],
                ['skipped_overlimit', (string) $stats['skipped_overlimit']],
                ['last_page_ok', (string) $lastPageOk],
                ['known_last_page_estimate', (int) $knownLastPage > 0 ? (string) $knownLastPage : '-'],
                ['max_episode_number_write', $animeOnly ? 'n/a' : (string) $maxEpisodeNumber],
                ['dry_run', $dryRun ? 'true' : 'false'],
                ['checkpoint', $noCheckpoint ? 'skipped' : 'written'],
                ['elapsed_s', (string) (int) round(microtime(true) - $startedAt)],
            ]
        );

        if ($examples !== []) {
            $this->line('Contoh grouping:');

            foreach ($examples as $example) {
                $this->line(' - ' . $example);
            }
        }

        if ($error !== null) {
            $this->error('Error: ' . $error);
        }

        return $status === 'ok' || $stats['items'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkpoint(
        int $lastPageOk,
        string $status,
        int $count,
        int $limit,
        int $start,
        int $maxPages,
        ?int $knownLastPage,
        bool $animeOnly,
        array $stats,
        ?string $error
    ): void {
        SyncState::query()->updateOrCreate(
            ['job_key' => 'sync:full'],
            [
                'last_page_ok' => $lastPageOk,
                'cursor' => (string) $lastPageOk,
                'last_status' => $status,
                'last_run_at' => now(),
                'meta' => [
                    'mode' => $animeOnly ? 'anime-only' : 'full',
                    'count' => $count,
                    'limit' => $limit,
                    'start' => $start,
                    'pages_requested' => $maxPages,
                    'known_last_page_estimate' => $knownLastPage,
                    'stats' => $stats,
                    'error' => $error,
                ],
            ]
        );
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
        bool $animeOnly,
        float $maxEpisodeNumber,
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

        if (!$animeOnly && (!is_array($quality) || $quality === [])) {
            $stats['skipped']++;

            return;
        }

        $number = $series['number'];

        if (!$dryRun && !$animeOnly && $number !== null && $number > $maxEpisodeNumber) {
            $stats['skipped_overlimit']++;

            if (count($examples) < 8) {
                $examples[] = sprintf(
                    'OVERLIMIT %s | ep=%s | %s',
                    Str::limit($series['title'], 55, ''),
                    number_format((float) $number, 2, '.', ''),
                    Str::limit((string) ($item['video_title'] ?? ''), 75, '')
                );
            }

            return;
        }

        if ($dryRun) {
            $seriesExists = Anime::query()
                ->where('source', 'animeku')
                ->where('external_id', $series['external_id'])
                ->exists();

            $stats[$seriesExists ? 'series_existing' : 'series_new']++;

            if (!$animeOnly) {
                $stats['ep_new']++;
            }

            if (count($examples) < 8) {
                $examples[] = sprintf(
                    '%s %s | ep=%s | %s',
                    $animeOnly ? 'CATALOG' : 'GROUP',
                    Str::limit($series['title'], 55, ''),
                    $number === null ? 'auto' : number_format((float) $number, 2, '.', ''),
                    Str::limit((string) ($item['video_title'] ?? ''), 75, '')
                );
            }

            return;
        }

        $anime = Anime::query()->firstOrNew([
            'source' => 'animeku',
            'external_id' => $series['external_id'],
        ]);

        $isNewAnime = !$anime->exists;

        if ($isNewAnime) {
            $anime->slug = $this->uniqueSlug($series['slug'], $series['external_id']);
            $anime->episodes_total = 0;
            $anime->popularity_score = 0;
            $anime->is_hidden = false;
        }

        $anime->title = $series['title'];
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
        $anime->save();

        $stats[$isNewAnime ? 'series_new' : 'series_existing']++;

        if ($animeOnly) {
            if (count($examples) < 8) {
                $examples[] = sprintf(
                    'CATALOG %s | cat=%s | item=%s',
                    Str::limit($series['title'], 55, ''),
                    (string) ($series['cat_id'] ?? '-'),
                    Str::limit((string) ($item['video_title'] ?? ''), 70, '')
                );
            }

            return;
        }

        if ($number === null) {
            $number = $this->nextNumber((int) $anime->id);
        } else {
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
        $episode->is_hidden = $episode->is_hidden ?? false;
        $episode->synced_at = now();
        $episode->save();

        $stats[$isNewEpisode ? 'ep_new' : 'ep_existing']++;

        if ($isNewEpisode) {
            $anime->increment('episodes_total');
        }

        if (count($examples) < 8) {
            $examples[] = sprintf(
                'GROUP %s | ep=%s | %s',
                Str::limit($series['title'], 55, ''),
                number_format((float) $number, 2, '.', ''),
                Str::limit((string) ($item['video_title'] ?? ''), 75, '')
            );
        }
    }

    private function extractLastPage(array $response, int $count): int
    {
        $total = (int) ($response['count_total'] ?? $response['total'] ?? 0);

        if ($total > 0 && $count > 0) {
            return (int) ceil($total / $count);
        }

        return 0;
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

        $candidate = round($max + 0.01, 2);

        if ($candidate <= 0) {
            $candidate = 0.01;
        }

        while ($candidate <= 999.99) {
            $exists = Episode::query()
                ->where('anime_id', $animeId)
                ->where('number', $candidate)
                ->exists();

            if (!$exists) {
                return $candidate;
            }

            $candidate = round($candidate + 0.01, 2);
        }

        return null;
    }
}
