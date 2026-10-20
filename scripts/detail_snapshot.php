<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/detail_snapshot.php
 * Fungsi : snapshot progres dan statistik final sync:detail-sweep
 * Rujuk  : Blueprint_ANIMEKU.md §3.3, §4; ZONAOTAKU_V2_FINAL.md §5, §13.5
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$final = in_array('--final', $argv ?? [], true);

$animeTotal = (int) DB::table('anime')->count();
$episodeTotal = (int) DB::table('episodes')->count();
$episodeWithStream = (int) DB::table('episodes')
    ->whereNotNull('quality_urls')
    ->where('quality_urls', '!=', '')
    ->count();
$episodeWithoutStream = (int) DB::table('episodes')
    ->where(static function ($q): void {
        $q->whereNull('quality_urls')
            ->orWhere('quality_urls', '=', '');
    })
    ->count();
$animeWithEpisodes = (int) DB::table('episodes')->count(DB::raw('DISTINCT anime_id'));
$animeWithoutEpisodes = max(0, $animeTotal - $animeWithEpisodes);
$maxNumber = DB::table('episodes')->max('number');
$gt999 = (int) DB::table('episodes')->where('number', '>', 999)->count();
$avg = $animeWithEpisodes > 0 ? round($episodeTotal / $animeWithEpisodes, 2) : 0.0;

printf(
    "DB anime=%d episodes=%d episodes_with_stream=%d episodes_without_stream=%d anime_with_episodes=%d anime_without_episodes=%d max_number=%s gt999=%d avg=%.2f\n",
    $animeTotal,
    $episodeTotal,
    $episodeWithStream,
    $episodeWithoutStream,
    $animeWithEpisodes,
    $animeWithoutEpisodes,
    (string) ($maxNumber ?? '0'),
    $gt999,
    $avg
);

$state = DB::table('sync_state')
    ->where('job_key', 'sync:detail-sweep')
    ->first();

if ($state === null) {
    echo "sync_state=missing\n";
    exit(0);
}

$meta = json_decode((string) ($state->meta ?? '{}'), true);

if (!is_array($meta)) {
    $meta = [];
}

$stats = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];

if (!$final) {
    printf(
        "STATE cid_index=%d status=%s run_at=%s items=%d ep_new=%d ep_exist=%d skipped=%d empty=%d errors=%d\n",
        (int) ($state->last_page_ok ?? 0),
        (string) ($state->last_status ?? '-'),
        (string) ($state->last_run_at ?? '-'),
        (int) ($stats['items'] ?? 0),
        (int) ($stats['ep_new'] ?? 0),
        (int) ($stats['ep_existing'] ?? 0),
        (int) ($stats['skipped'] ?? 0),
        (int) ($stats['empty_details'] ?? 0),
        (int) ($stats['detail_errors'] ?? 0)
    );

    exit(0);
}

printf("sync_state_cid_index=%d\n", (int) ($state->last_page_ok ?? 0));
printf("sync_state_status=%s\n", (string) ($state->last_status ?? '-'));
printf("sync_state_run_at=%s\n", (string) ($state->last_run_at ?? '-'));
printf("sync_state_total_cids=%s\n", (string) ($meta['total_cids'] ?? '-'));
printf("sync_state_items=%d\n", (int) ($stats['items'] ?? 0));
printf("sync_state_anime_new=%d\n", (int) ($stats['anime_new'] ?? 0));
printf("sync_state_anime_existing=%d\n", (int) ($stats['anime_existing'] ?? 0));
printf("sync_state_ep_new=%d\n", (int) ($stats['ep_new'] ?? 0));
printf("sync_state_ep_existing=%d\n", (int) ($stats['ep_existing'] ?? 0));
printf("sync_state_skipped=%d\n", (int) ($stats['skipped'] ?? 0));
printf("sync_state_empty_details=%d\n", (int) ($stats['empty_details'] ?? 0));
printf("sync_state_detail_errors=%d\n", (int) ($stats['detail_errors'] ?? 0));
printf("sync_state_meta=%s\n", (string) ($state->meta ?? '-'));
