<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/episodes_snapshot.php
 * Fungsi : snapshot progres dan statistik final sync:episodes tanpa php -r inline
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5, §13.5
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$final = in_array('--final', $argv ?? [], true);

$state = DB::table('sync_state')
    ->where('job_key', 'sync:episodes')
    ->first();

if ($final) {
    $animeTotal = (int) DB::table('anime')->count();
    $episodesTotal = (int) DB::table('episodes')->count();
    $animeWithEpisodes = (int) DB::table('episodes')->count(DB::raw('DISTINCT anime_id'));
    $episodesWithStream = (int) DB::table('episodes')
        ->whereNotNull('quality_urls')
        ->where('quality_urls', '!=', '')
        ->count();
    $episodesWithoutStream = (int) DB::table('episodes')
        ->where(static function ($q): void {
            $q->whereNull('quality_urls')
                ->orWhere('quality_urls', '=', '');
        })
        ->count();
    $maxNumber = DB::table('episodes')->max('number');
    $gt999 = (int) DB::table('episodes')->where('number', '>', 999)->count();
    $avg = $animeWithEpisodes > 0 ? round($episodesTotal / $animeWithEpisodes, 2) : 0.0;

    echo "--- FINAL DB STATS ---\n";
    printf("anime_total=%d\n", $animeTotal);
    printf("episodes_total=%d\n", $episodesTotal);
    printf("anime_with_episodes=%d\n", $animeWithEpisodes);
    printf("episodes_with_stream=%d\n", $episodesWithStream);
    printf("episodes_without_stream=%d\n", $episodesWithoutStream);
    printf("max_episode_number=%s\n", (string) ($maxNumber ?? '0'));
    printf("episodes_gt_999=%d\n", $gt999);
    printf("avg_episodes_per_active_anime=%.2f\n", $avg);
}

if ($state === null) {
    echo "sync_state=missing\n";
    exit(0);
}

$meta = json_decode((string) ($state->meta ?? '{}'), true);

if (!is_array($meta)) {
    $meta = [];
}

$stats = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];
$emptyPages = is_array($meta['empty_pages'] ?? null) ? $meta['empty_pages'] : [];

if (!$final) {
    printf(
        "  last_page=%d status=%s run_at=%s items=%d ep_new=%d ep_exist=%d skipped=%d empty=%d errors=%d\n",
        (int) ($state->last_page_ok ?? 0),
        (string) ($state->last_status ?? '-'),
        (string) ($state->last_run_at ?? '-'),
        (int) ($stats['items'] ?? 0),
        (int) ($stats['ep_new'] ?? 0),
        (int) ($stats['ep_existing'] ?? 0),
        (int) ($stats['skipped'] ?? 0),
        (int) ($stats['empty_pages'] ?? 0),
        (int) ($stats['request_errors'] ?? 0)
    );

    exit(0);
}

printf("sync_state_last_page=%d\n", (int) ($state->last_page_ok ?? 0));
printf("sync_state_status=%s\n", (string) ($state->last_status ?? '-'));
printf("sync_state_run_at=%s\n", (string) ($state->last_run_at ?? '-'));
printf("sync_state_known_end_page=%s\n", (string) ($meta['known_end_page'] ?? '-'));
printf("sync_state_empty_pages_count=%d\n", count($emptyPages));

if ($emptyPages !== []) {
    printf(
        "sync_state_empty_pages_sample=%s\n",
        implode(',', array_slice($emptyPages, 0, 50))
    );
}

printf("sync_state_meta=%s\n", (string) ($state->meta ?? '-'));
