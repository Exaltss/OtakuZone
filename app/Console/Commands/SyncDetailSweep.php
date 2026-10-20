<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncDetailSweep.php
 * Fungsi : enumerasi seri via get_category_popular, tarik full episode + stream via get_anime_detail?id=<cat_id/cid>
 * Rujuk  : Blueprint_ANIMEKU.md §3.2, §3.3, §4; metadata turunan user
 *
 * Identity:
 * - anime: cat_id/cid sebagai kunci utama seri
 * - episode: video_id/vid sebagai kunci utama episode
 * - stream: video_url* / url_* langsung dari item episode
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SyncDetailSweep extends Command
{
    private const JOB = 'sync:detail-sweep';

    protected $signature = 'sync:detail-sweep
                            {--count-popular=20 : Item per page popular}
                            {--pages-popular=0 : Batas page popular; 0 = semua}
                            {--limit-cids=0 : Batas jumlah seri yang disweep; 0 = semua}
                            {--start=0 : Index cid mulai; 0 = resume checkpoint}
                            {--seed-cids= : CID manual, pisahkan koma; jika diisi tidak fetch popular}
                            {--reset : Hapus checkpoint dan cid cache}
                            {--dry-run : Jangan tulis DB}
                            {--no-checkpoint : Jangan tulis sync_state}
                            {--throttle=700 : Throttle ms}
                            {--jitter=250 : Jitter ms maksimum}
                            {--progress=5 : Cetak progres tiap N seri}
                            {--request-retries=3 : Retry request per seri}
                            {--cid-file=storage/app/sync/detail-cids.json : Cache daftar cid}';

    protected $description = 'Full episode + stream sweep melalui get_category_popular dan get_anime_detail, identity cat_id+video_id';

    public function handle(
        AnimekuClient $client,
        ResponseMapper $mapper,
        SeriesResolver $resolver
    ): int {
        $countPopular = max(1, min(100, (int) $this->option('count-popular')));
        $pagesPopular = max(0, (int) $this->option('pages-popular'));
        $limitCids = max(0, (int) $this->option('limit-cids'));
        $startOption = max(0, (int) $this->option('start'));
        $seedRaw = trim((string) $this->option('seed-cids'));
        $reset = (bool) $this->option('reset');
        $dryRun = (bool) $this->option('dry-run');
        $noCheckpoint = (bool) $this->option('no-checkpoint');
        $throttle = max(0, (int) $this->option('throttle'));
        $jitter = max(0, (int) $this->option('jitter'));
        $progressEvery = max(1, (int) $this->option('progress'));
        $requestRetries = max(1, (int) $this->option('request-retries'));
        $cidFile = trim((string) $this->option('cid-file')) ?: 'storage/app/sync/detail-cids.json';
        $cidPath = base_path($cidFile);

        @mkdir(dirname($cidPath), 0775, true);

        config([
            'zonaotaku.animeku.throttle_ms' => $throttle,
            'zonaotaku.animeku.jitter_ms' => $jitter,
        ]);

        if ($reset) {
            SyncState::query()->where('job_key', self::JOB)->delete();
            @unlink($cidPath);
        }

        $cids = [];
        $usedSeed = false;

        if ($seedRaw !== '') {
            $usedSeed = true;

            foreach (explode(',', $seedRaw) as $part) {
                $cid = (int) trim($part);

                if ($cid > 0 && !in_array($cid, $cids, true)) {
                    $cids[] = $cid;
                }
            }
        } elseif (!$reset && is_file($cidPath)) {
            $json = json_decode((string) file_get_contents($cidPath), true);

            if (is_array($json)) {
                foreach ($json as $cid) {
                    $cid = (int) $cid;

                    if ($cid > 0 && !in_array($cid, $cids, true)) {
                        $cids[] = $cid;
                    }
                }
            }
        }

        if ($cids === [] && !$usedSeed) {
            $this->info('Mengumpulkan daftar cid dari get_category_popular...');
            $cids = $this->collectPopularCids($client, $countPopular, $pagesPopular);

            if ($cids !== []) {
                file_put_contents($cidPath, json_encode($cids, JSON_UNESCAPED_SLASHES));
            }
        }

        if ($cids === []) {
            $this->error('Daftar cid kosong.');

            return self::FAILURE;
        }

        $state = SyncState::firstOrNew(['job_key' => self::JOB]);
        $start = $startOption > 0 ? $startOption : max(0, (int) ($state->last_page_ok ?? 0));
        $totalCids = count($cids);

        if ($start >= $totalCids) {
            $this->info('Sweep sudah tuntas pada checkpoint terakhir.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $processed = 0;
        $status = 'ok';
        $error = null;

        $stats = [
            'cids_total' => $totalCids,
            'cids_start' => $start,
            'cids_processed' => 0,
            'detail_errors' => 0,
            'empty_details' => 0,
            'items' => 0,
            'anime_new' => 0,
            'anime_existing' => 0,
            'ep_new' => 0,
            'ep_existing' => 0,
            'ep_conflict_keep' => 0,
            'ep_conflict_auto' => 0,
            'ep_conflict_skip' => 0,
            'skipped' => 0,
        ];

        $examples = [];

        for ($i = $start; $i < $totalCids; $i++) {
            if ($limitCids > 0 && $processed >= $limitCids) {
                break;
            }

            $cid = (int) $cids[$i];
            $processed++;

            $response = $this->fetchDetail($client, $cid, $requestRetries);

            if ($response === null) {
                $stats['detail_errors']++;
                $status = 'partial';
                $error = "detail_failed_cid_{$cid}";

                $this->error(sprintf('[%s] cid=%d detail_error', now()->toDateTimeString(), $cid));

                continue;
            }

            $items = $this->extractEpisodeItems($response);

            if ($items === []) {
                $stats['empty_details']++;

                $this->warn(sprintf('[%s] cid=%d empty_detail', now()->toDateTimeString(), $cid));

                continue;
            }

            if ($dryRun) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $this->processItem($item, $mapper, $resolver, true, $stats, $examples);
                    }
                }
            } else {
                DB::transaction(function () use ($items, $mapper, $resolver, &$stats, &$examples): void {
                    foreach ($items as $item) {
                        if (is_array($item)) {
                            $this->processItem($item, $mapper, $resolver, false, $stats, $examples);
                        }
                    }
                });
            }

            $stats['cids_processed'] = $processed;
            $completed = $i + 1;

            if (!$dryRun && !$noCheckpoint && ($processed % $progressEvery === 0 || $completed >= $totalCids)) {
                $this->checkpoint($completed, 'ok', $countPopular, $pagesPopular, $limitCids, $start, $totalCids, $stats, null);
            }

            if ($processed % $progressEvery === 0) {
                $elapsed = (int) round(microtime(true) - $startedAt);

                $this->line(sprintf(
                    '[%s] cid_index=%d/%d cid=%d items=%d anime_new=%d anime_exist=%d ep_new=%d ep_exist=%d conflict_keep=%d conflict_skip=%d skipped=%d empty=%d errors=%d elapsed=%ds',
                    now()->toDateTimeString(),
                    $completed,
                    $totalCids,
                    $cid,
                    $stats['items'],
                    $stats['anime_new'],
                    $stats['anime_existing'],
                    $stats['ep_new'],
                    $stats['ep_existing'],
                    $stats['ep_conflict_keep'],
                    $stats['ep_conflict_skip'],
                    $stats['skipped'],
                    $stats['empty_details'],
                    $stats['detail_errors'],
                    $elapsed
                ));
            }
        }

        if (!$dryRun && !$noCheckpoint) {
            $this->checkpoint(
                min($totalCids, $start + $processed),
                $status,
                $countPopular,
                $pagesPopular,
                $limitCids,
                $start,
                $totalCids,
                $stats,
                $error
            );
        }

        if (!$dryRun) {
            DB::statement('
                UPDATE anime
                SET episodes_total = (
                    SELECT COUNT(*)
                    FROM episodes
                    WHERE episodes.anime_id = anime.id
                )
            ');
        }

        $this->table(
            ['metric', 'value'],
            [
                ['status', $status],
                ['cids_total', (string) $stats['cids_total']],
                ['cids_start', (string) $stats['cids_start']],
                ['cids_processed', (string) $stats['cids_processed']],
                ['items', (string) $stats['items']],
                ['anime_new', (string) $stats['anime_new']],
                ['anime_existing', (string) $stats['anime_existing']],
                ['ep_new', (string) $stats['ep_new']],
                ['ep_existing', (string) $stats['ep_existing']],
                ['ep_conflict_keep', (string) $stats['ep_conflict_keep']],
                ['ep_conflict_auto', (string) $stats['ep_conflict_auto']],
                ['ep_conflict_skip', (string) $stats['ep_conflict_skip']],
                ['skipped', (string) $stats['skipped']],
                ['empty_details', (string) $stats['empty_details']],
                ['detail_errors', (string) $stats['detail_errors']],
                ['dry_run', $dryRun ? 'true' : 'false'],
                ['checkpoint', $noCheckpoint ? 'skipped' : 'written'],
                ['seed_mode', $usedSeed ? 'true' : 'false'],
                ['elapsed_s', (string) (int) round(microtime(true) - $startedAt)],
            ]
        );

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
     * @return array<int,int>
     */
    private function collectPopularCids(
        AnimekuClient $client,
        int $count,
        int $maxPages
    ): array {
        $cids = [];
        $seen = [];
        $page = 1;
        $knownTotal = null;
        $consecutiveErrors = 0;

        while (true) {
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }

            try {
                $response = $client->popular($page, $count);
                $consecutiveErrors = 0;
            } catch (Throwable $e) {
                $consecutiveErrors++;
                $this->warn(sprintf('popular page=%d error=%s', $page, $e->getMessage()));

                if ($consecutiveErrors >= 5) {
                    break;
                }

                sleep(2);
                $page++;

                continue;
            }

            if ($knownTotal === null) {
                $knownTotal = (int) ($response['count_total'] ?? 0);
            }

            $items = $this->extractPopularItems($response);

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $cid = (int) ($item['cid'] ?? $item['cat_id'] ?? 0);

                if ($cid > 0 && !isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $cids[] = $cid;
                }
            }

            if ($knownTotal !== null && $knownTotal > 0 && count($cids) >= $knownTotal) {
                break;
            }

            if ($maxPages > 0 && $page >= $maxPages) {
                break;
            }

            $page++;
        }

        $this->info('CID terkumpul: ' . count($cids));

        return $cids;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchDetail(AnimekuClient $client, int $cid, int $retries): ?array
    {
        for ($attempt = 0; $attempt < $retries; $attempt++) {
            try {
                $response = $client->detail((string) $cid);

                if (is_array($response)) {
                    return $response;
                }
            } catch (Throwable) {
                sleep(min(10, 1 + $attempt));
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<int,mixed>
     */
    private function extractPopularItems(array $response): array
    {
        foreach (['new_anime', 'latest_anime', 'data'] as $key) {
            $value = $response[$key] ?? null;

            if (is_array($value) && $value !== [] && array_is_list($value)) {
                return $value;
            }
        }

        foreach ($response as $value) {
            if (!is_array($value) || $value === [] || !array_is_list($value)) {
                continue;
            }

            $first = $value[0] ?? null;

            if (is_array($first) && (isset($first['cid']) || isset($first['category_name']))) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<int,mixed>
     */
    private function extractEpisodeItems(array $response): array
    {
        $best = [];
        $bestScore = 0;

        foreach ($response as $value) {
            if (!is_array($value) || $value === [] || !array_is_list($value)) {
                continue;
            }

            $score = 0;

            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (
                    isset($item['video_title'])
                    || isset($item['title'])
                    || isset($item['vid'])
                    || isset($item['video_id'])
                    || isset($item['video_url'])
                    || isset($item['url_1080'])
                    || isset($item['url_auto'])
                ) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $value;
            }
        }

        return $best;
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
        $episodeExternalId = trim((string) ($series['episode_external_id'] ?? ''));
        $catId = isset($series['cat_id']) ? (int) $series['cat_id'] : null;

        if ($episodeExternalId === '') {
            $stats['skipped']++;

            return;
        }

        if (!is_array($quality) || $quality === []) {
            $stats['skipped']++;

            return;
        }

        $anime = $this->findAnime($catId, (string) $series['external_id']);
        $isNewAnime = !$anime->exists;

        if ($isNewAnime) {
            $anime->source = 'animeku';
            $anime->external_id = (string) $series['external_id'];
            $anime->slug = $this->uniqueSlug((string) $series['slug'], (string) $anime->external_id);
            $anime->episodes_total = 0;
            $anime->popularity_score = 0;
            $anime->is_hidden = false;
        } else {
            if ((string) $anime->source === '') {
                $anime->source = 'animeku';
            }

            if ((string) $anime->external_id === '') {
                $anime->external_id = (string) $series['external_id'];
            }

            if ((string) $anime->slug === '') {
                $anime->slug = $this->uniqueSlug((string) $series['slug'], (string) $anime->external_id);
            }
        }

        $anime->title = (string) $series['title'];

        if ($catId !== null) {
            $anime->cat_id = $catId;
        }

        $this->setAnimeAttribute($anime, 'synopsis', $mapped['anime']['synopsis'] ?? null);
        $this->setAnimeAttribute($anime, 'type', $mapped['anime']['type'] ?? null);
        $this->setAnimeAttribute($anime, 'status', $mapped['anime']['status'] ?? null);
        $this->setAnimeAttribute($anime, 'year', $mapped['anime']['year'] ?? null);
        $this->setAnimeAttribute($anime, 'season', $mapped['anime']['season'] ?? null);
        $this->setAnimeAttribute($anime, 'rating_avg', $mapped['anime']['rating_avg'] ?? null);
        $this->setAnimeAttribute($anime, 'view_count', $mapped['anime']['view_count'] ?? null);
        $this->setAnimeAttribute($anime, 'raw_meta', $mapped['anime']['raw_meta'] ?? null);

        $anime->synced_at = now();

        if (!$dryRun) {
            $anime->save();
        }

        $stats[$isNewAnime ? 'anime_new' : 'anime_existing']++;

        $number = $series['number'] ?? 1.0;

        if (!is_numeric($number) || (float) $number <= 0) {
            $number = 1.0;
        }

        $number = round((float) $number, 2);

        if ($dryRun) {
            $stats['ep_new']++;

            if (count($examples) < 10) {
                $examples[] = sprintf(
                    'DRY cid=%s | %s | ep=%s | %s',
                    (string) ($catId ?? '-'),
                    Str::limit($series['title'], 45, ''),
                    number_format((float) $number, 2, '.', ''),
                    Str::limit((string) ($item['video_title'] ?? $item['title'] ?? ''), 65, '')
                );
            }

            return;
        }

        $episode = $this->findEpisodeByExternal((int) $anime->id, $episodeExternalId);
        $isNewEpisode = !$episode->exists;
        $originalNumber = $episode->exists ? (float) $episode->number : 0.0;
        $targetNumber = $number;

        $occupiedByOther = Episode::query()
            ->where('anime_id', $anime->id)
            ->where('number', $targetNumber)
            ->where('external_id', '!=', $episodeExternalId)
            ->exists();

        if ($occupiedByOther) {
            if ($episode->exists && $originalNumber > 0) {
                $targetNumber = $originalNumber;
                $stats['ep_conflict_keep']++;
            } else {
                $autoNumber = $this->nextNumber((int) $anime->id);

                if ($autoNumber === null) {
                    $stats['ep_conflict_skip']++;

                    return;
                }

                $targetNumber = $autoNumber;
                $stats['ep_conflict_auto']++;
            }
        }

        $episode->anime_id = (int) $anime->id;
        $episode->external_id = $episodeExternalId;
        $episode->number = $targetNumber;
        $episode->title = (string) ($item['video_title'] ?? $item['title'] ?? 'Episode');
        $episode->thumbnail_path = $mapped['episode']['thumbnail_path'] ?? $episode->thumbnail_path ?? null;
        $episode->duration_sec = $mapped['episode']['duration_sec'] ?? $episode->duration_sec ?? null;
        $episode->quality_urls = $quality;
        $episode->is_hidden = false;
        $episode->synced_at = now();

        try {
            $episode->save();
        } catch (UniqueConstraintViolationException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'uq_ep_external')) {
                $stats['ep_conflict_skip']++;

                return;
            }

            if ($episode->exists && $originalNumber > 0) {
                $episode->number = $originalNumber;

                try {
                    $episode->save();
                    $stats['ep_conflict_keep']++;
                } catch (UniqueConstraintViolationException) {
                    $stats['ep_conflict_skip']++;
                }

                return;
            }

            $stats['ep_conflict_skip']++;

            return;
        }

        $stats[$isNewEpisode ? 'ep_new' : 'ep_existing']++;

        if (count($examples) < 10) {
            $examples[] = sprintf(
                'SWEEP cid=%s | %s | ep=%s | %s',
                (string) ($catId ?? '-'),
                Str::limit($series['title'], 45, ''),
                number_format((float) $episode->number, 2, '.', ''),
                Str::limit((string) ($item['video_title'] ?? $item['title'] ?? ''), 65, '')
            );
        }
    }

    private function findAnime(?int $catId, string $externalId): Anime
    {
        if ($catId !== null && $catId > 0) {
            $anime = Anime::query()
                ->where('source', 'animeku')
                ->where('cat_id', $catId)
                ->orderByDesc('episodes_total')
                ->orderBy('id')
                ->first();

            if ($anime !== null) {
                return $anime;
            }
        }

        $anime = Anime::query()
            ->where('source', 'animeku')
            ->where('external_id', $externalId)
            ->first();

        if ($anime !== null) {
            return $anime;
        }

        return new Anime([
            'source' => 'animeku',
            'external_id' => $externalId,
        ]);
    }

    private function findEpisodeByExternal(int $animeId, string $externalId): Episode
    {
        $rows = Episode::query()
            ->where('anime_id', $animeId)
            ->where('external_id', $externalId)
            ->orderBy('id')
            ->get();

        if ($rows->count() > 1) {
            $keep = $rows->first();
            $ids = $rows->skip(1)->pluck('id')->all();

            if ($ids !== []) {
                DB::table('watch_history')->whereIn('episode_id', $ids)->delete();
                Episode::query()->whereIn('id', $ids)->delete();
            }

            return $keep instanceof Episode ? $keep : new Episode([
                'anime_id' => $animeId,
                'external_id' => $externalId,
            ]);
        }

        $first = $rows->first();

        if ($first instanceof Episode) {
            return $first;
        }

        return new Episode([
            'anime_id' => $animeId,
            'external_id' => $externalId,
        ]);
    }

    private function setAnimeAttribute(Anime $anime, string $attribute, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_string($value) && trim($value) === '') {
            return;
        }

        if (is_array($value) && $value === []) {
            return;
        }

        $anime->{$attribute} = $value;
    }

    private function checkpoint(
        int $completed,
        string $status,
        int $countPopular,
        int $pagesPopular,
        int $limitCids,
        int $start,
        int $totalCids,
        array $stats,
        ?string $error
    ): void {
        SyncState::query()->updateOrCreate(
            ['job_key' => self::JOB],
            [
                'last_page_ok' => $completed,
                'cursor' => (string) $completed,
                'last_status' => $status,
                'last_run_at' => now(),
                'meta' => [
                    'mode' => 'popular-to-detail',
                    'identity' => 'cat_id+video_id',
                    'count_popular' => $countPopular,
                    'pages_popular' => $pagesPopular,
                    'limit_cids' => $limitCids,
                    'start' => $start,
                    'total_cids' => $totalCids,
                    'stats' => $stats,
                    'error' => $error,
                ],
            ]
        );
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

        if ($max <= 0) {
            return 1.0;
        }

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
