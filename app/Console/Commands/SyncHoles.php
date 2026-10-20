<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncHoles.php
 * Fungsi : mengisi lubang halaman kosong dari sync:episodes dengan retry offset presisi
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §5.4, Blueprint_ANIMEKU §6
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
use Throwable;

final class SyncHoles extends Command
{
    private const SOURCE_JOB = 'sync:episodes';
    private const JOB = 'sync:holes';

    protected $signature = 'sync:holes
                            {--counts=20,50,100 : Daftar count retry untuk mengisi lubang}
                            {--throttle=700 : Throttle ms per request}
                            {--jitter=250 : Jitter ms maksimum}
                            {--empty-retries=2 : Retry jika halaman target kosong}
                            {--request-retries=3 : Retry jika request error}
                            {--dry-run : Jangan tulis DB}
                            {--no-recalc : Jangan recalc anime.episodes_total}';

    protected $description = 'Isi lubang halaman kosong dari sync:episodes dengan mapping offset yang benar';

    public function handle(
        AnimekuClient $client,
        ResponseMapper $mapper,
        SeriesResolver $resolver
    ): int {
        $throttle = max(0, (int) $this->option('throttle'));
        $jitter = max(0, (int) $this->option('jitter'));
        $emptyRetries = max(1, (int) $this->option('empty-retries'));
        $requestRetries = max(1, (int) $this->option('request-retries'));
        $dryRun = (bool) $this->option('dry-run');
        $noRecalc = (bool) $this->option('no-recalc');

        config([
            'zonaotaku.animeku.throttle_ms' => $throttle,
            'zonaotaku.animeku.jitter_ms' => $jitter,
        ]);

        $retryCounts = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $this->option('counts'))),
            static fn (int $c): bool => $c > 0 && $c <= 100
        )));

        if ($retryCounts === []) {
            $retryCounts = [20, 50, 100];
        }

        $state = SyncState::query()->where('job_key', self::SOURCE_JOB)->first();
        $rawMeta = $state === null ? null : ($state->meta ?? null);

        if (is_array($rawMeta)) {
            $meta = $rawMeta;
        } elseif (is_string($rawMeta) && $rawMeta !== '') {
            $meta = json_decode($rawMeta, true);
        } else {
            $meta = [];
        }

        if (!is_array($meta)) {
            $meta = [];
        }

        $sourceCount = (int) ($meta['count'] ?? 100);

        if ($sourceCount < 1 || $sourceCount > 100) {
            $sourceCount = 100;
        }

        $pages = is_array($meta['empty_pages'] ?? null)
            ? array_values(array_unique(array_map('intval', $meta['empty_pages'])))
            : [];

        $pages = array_values(array_filter($pages, static fn (int $p): bool => $p > 0));

        if ($pages === []) {
            $this->info('Tidak ada lubang halaman kosong dari sync:episodes.');

            return self::SUCCESS;
        }

        $this->info('Lubang terdeteksi: ' . count($pages) . ' halaman source.');
        $this->info('Source count: ' . $sourceCount);
        $this->info('Retry counts: ' . implode(',', $retryCounts));

        $startedAt = microtime(true);

        $stats = [
            'hole_pages' => count($pages),
            'recovered_holes' => 0,
            'still_empty_holes' => 0,
            'target_requests' => 0,
            'items' => 0,
            'anime_new' => 0,
            'anime_existing' => 0,
            'ep_new' => 0,
            'ep_existing' => 0,
            'skipped' => 0,
            'request_errors' => 0,
        ];

        $recoveredHoles = [];
        $stillEmptyHoles = [];
        $errors = [];
        $examples = [];
        $seen = [];
        $processed = 0;

        foreach ($pages as $holePage) {
            $processed++;

            $startItem = (($holePage - 1) * $sourceCount) + 1;
            $endItem = $holePage * $sourceCount;

            $holeRecovered = false;

            foreach ($retryCounts as $count) {
                $targetStart = intdiv($startItem - 1, $count) + 1;
                $targetEnd = intdiv($endItem - 1, $count) + 1;

                for ($targetPage = $targetStart; $targetPage <= $targetEnd; $targetPage++) {
                    $key = $count . ':' . $targetPage;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    for ($emptyAttempt = 0; $emptyAttempt < $emptyRetries; $emptyAttempt++) {
                        $stats['target_requests']++;

                        $items = null;

                        for ($requestAttempt = 0; $requestAttempt < $requestRetries; $requestAttempt++) {
                            try {
                                $response = $client->latest($targetPage, $count);
                                $items = $response['latest_anime'] ?? $response['data'] ?? [];
                                break;
                            } catch (Throwable $e) {
                                $stats['request_errors']++;

                                $message = sprintf(
                                    'hole=%d count=%d page=%d attempt=%d error=%s',
                                    $holePage,
                                    $count,
                                    $targetPage,
                                    $requestAttempt + 1,
                                    $e->getMessage()
                                );

                                if (count($errors) < 200) {
                                    $errors[] = $message;
                                }

                                sleep(min(10, 1 + $requestAttempt));
                            }
                        }

                        if (is_array($items) && $items !== []) {
                            foreach ($items as $item) {
                                if (is_array($item)) {
                                    $this->processItem($item, $mapper, $resolver, $dryRun, $stats, $examples);
                                }
                            }

                            $holeRecovered = true;
                            break;
                        }

                        if ($emptyAttempt + 1 < $emptyRetries) {
                            sleep(1);
                        }
                    }
                }

                if ($holeRecovered) {
                    break;
                }
            }

            if ($holeRecovered) {
                $stats['recovered_holes']++;
                $recoveredHoles[] = $holePage;
            } else {
                $stats['still_empty_holes']++;
                $stillEmptyHoles[] = $holePage;
            }

            if ($processed % 10 === 0 || $processed === count($pages)) {
                $elapsed = (int) round(microtime(true) - $startedAt);

                $this->line(sprintf(
                    '[%s] hole=%d/%d recovered=%d still_empty=%d requests=%d items=%d ep_new=%d ep_exist=%d skipped=%d errors=%d elapsed=%ds',
                    now()->toDateTimeString(),
                    $processed,
                    count($pages),
                    $stats['recovered_holes'],
                    $stats['still_empty_holes'],
                    $stats['target_requests'],
                    $stats['items'],
                    $stats['ep_new'],
                    $stats['ep_existing'],
                    $stats['skipped'],
                    $stats['request_errors'],
                    $elapsed
                ));
            }
        }

        if (!$dryRun && !$noRecalc) {
            DB::statement('
                UPDATE anime a
                SET a.episodes_total = (
                    SELECT COUNT(*)
                    FROM episodes e
                    WHERE e.anime_id = a.id
                )
            ');
        }

        SyncState::query()->updateOrCreate(
            ['job_key' => self::JOB],
            [
                'last_page_ok' => $stats['recovered_holes'],
                'cursor' => (string) $stats['recovered_holes'],
                'last_status' => $stats['still_empty_holes'] === 0 ? 'ok' : 'partial',
                'last_run_at' => now(),
                'meta' => [
                    'source_job' => self::SOURCE_JOB,
                    'source_count' => $sourceCount,
                    'retry_counts' => $retryCounts,
                    'stats' => $stats,
                    'recovered_holes' => array_slice($recoveredHoles, 0, 500),
                    'still_empty_holes' => array_slice($stillEmptyHoles, 0, 500),
                    'errors' => array_slice($errors, 0, 100),
                    'dry_run' => $dryRun,
                ],
            ]
        );

        $this->table(
            ['metric', 'value'],
            [
                ['status', $stats['still_empty_holes'] === 0 ? 'ok' : 'partial'],
                ['hole_pages', (string) $stats['hole_pages']],
                ['recovered_holes', (string) $stats['recovered_holes']],
                ['still_empty_holes', (string) $stats['still_empty_holes']],
                ['target_requests', (string) $stats['target_requests']],
                ['items', (string) $stats['items']],
                ['anime_new', (string) $stats['anime_new']],
                ['anime_existing', (string) $stats['anime_existing']],
                ['ep_new', (string) $stats['ep_new']],
                ['ep_existing', (string) $stats['ep_existing']],
                ['skipped', (string) $stats['skipped']],
                ['request_errors', (string) $stats['request_errors']],
                ['dry_run', $dryRun ? 'true' : 'false'],
                ['recalc', $noRecalc ? 'skipped' : 'done'],
                ['elapsed_s', (string) (int) round(microtime(true) - $startedAt)],
            ]
        );

        if ($stillEmptyHoles !== []) {
            $this->line('Masih kosong: ' . implode(',', array_slice($stillEmptyHoles, 0, 100)));
        }

        if ($errors !== []) {
            $this->line('Error sampel:');

            foreach (array_slice($errors, 0, 10) as $error) {
                $this->line(' - ' . $error);
            }
        }

        if ($examples !== []) {
            $this->line('Contoh item yang diisi:');

            foreach ($examples as $example) {
                $this->line(' - ' . $example);
            }
        }

        return self::SUCCESS;
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
        $animeId = $dryRun ? 0 : (int) $anime->id;

        if ($number === null) {
            $number = $dryRun ? 0.01 : $this->nextNumber($animeId);
        } elseif (!$dryRun) {
            $collision = Episode::query()
                ->where('anime_id', $animeId)
                ->where('number', $number)
                ->where('external_id', '!=', $episodeExternalId)
                ->exists();

            if ($collision) {
                $number = $this->nextNumber($animeId);
            }
        }

        if ($number === null) {
            $stats['skipped']++;

            return;
        }

        if ($dryRun) {
            $stats['ep_new']++;

            if (count($examples) < 10) {
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
            'anime_id' => $animeId,
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

        if (count($examples) < 10) {
            $examples[] = sprintf(
                'HOLE %s | ep=%s | %s',
                Str::limit($series['title'], 55, ''),
                number_format((float) $number, 2, '.', ''),
                Str::limit((string) ($item['video_title'] ?? ''), 75, '')
            );
        }
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

        $maxHundredths = (int) round($max * 100);

        for ($h = $maxHundredths + 1; $h <= 99999999; $h++) {
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
