<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/final_audit.php
 * Fungsi : audit final episode/stream, missing cid, dan status cid bermasalah
 * Rujuk  : Blueprint_ANIMEKU.md §4, §6; ZONAOTAKU_V2_FINAL.md §13.5
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$specific = [1273, 5902, 5906, 5933, 4208, 1650, 4278, 704];

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

$state = DB::table('sync_state')
    ->where('job_key', 'sync:detail-sweep')
    ->first();

$stateItems = 0;
$stateSkipped = 0;
$stateEmpty = 0;
$stateErrors = 0;

if ($state !== null) {
    $meta = json_decode((string) ($state->meta ?? '{}'), true);

    if (is_array($meta) && is_array($meta['stats'] ?? null)) {
        $stats = $meta['stats'];
        $stateItems = (int) ($stats['items'] ?? 0);
        $stateSkipped = (int) ($stats['skipped'] ?? 0);
        $stateEmpty = (int) ($stats['empty_details'] ?? 0);
        $stateErrors = (int) ($stats['detail_errors'] ?? 0);
    }
}

echo "--- FINAL GLOBAL ---\n";
printf("upstream_state_items=%d\n", $stateItems);
printf("state_skipped=%d\n", $stateSkipped);
printf("state_empty_details=%d\n", $stateEmpty);
printf("state_detail_errors=%d\n", $stateErrors);
printf("db_anime=%d\n", $animeTotal);
printf("db_episodes=%d\n", $episodeTotal);
printf("db_episodes_with_stream=%d\n", $episodeWithStream);
printf("db_episodes_without_stream=%d\n", $episodeWithoutStream);
printf("db_anime_with_episodes=%d\n", $animeWithEpisodes);
printf("db_anime_without_episodes=%d\n", $animeWithoutEpisodes);
printf("db_max_episode_number=%s\n", (string) ($maxNumber ?? '0'));
printf("db_episodes_gt_999=%d\n", $gt999);
printf("db_avg_episodes_per_active_anime=%.2f\n", $avg);
printf("stream_integrity=%s\n", $episodeWithoutStream === 0 ? 'OK' : 'FAIL');

echo "\n--- SPECIFIC CID STATUS ---\n";

$counts = [];
$animeRows = [];

$rows = DB::table('anime')
    ->leftJoin('episodes', 'episodes.anime_id', '=', 'anime.id')
    ->whereIn('anime.cat_id', $specific)
    ->selectRaw('anime.cat_id AS cat_id, anime.id AS anime_id, anime.title AS title, COUNT(episodes.id) AS episode_count')
    ->groupBy('anime.cat_id', 'anime.id', 'anime.title')
    ->get();

foreach ($rows as $row) {
    $cat = (int) $row->cat_id;
    $counts[$cat] = ($counts[$cat] ?? 0) + (int) $row->episode_count;

    if (!isset($animeRows[$cat]) || (int) ($animeRows[$cat]['episode_count'] ?? 0) < (int) $row->episode_count) {
        $animeRows[$cat] = [
            'anime_id' => (int) $row->anime_id,
            'title' => (string) $row->title,
            'episode_count' => (int) $row->episode_count,
        ];
    }
}

foreach ($specific as $cid) {
    $count = (int) ($counts[$cid] ?? 0);
    $anime = $animeRows[$cid] ?? null;

    printf(
        "cid=%d | episodes=%d | anime_id=%s | title=%s\n",
        $cid,
        $count,
        $anime === null ? '-' : (string) $anime['anime_id'],
        $anime === null ? 'MISSING_ANIME_ROW' : (string) $anime['title']
    );
}

echo "\n--- MISSING POPULAR CID AFTER REPAIR ---\n";

$cidFile = base_path('storage/app/sync/detail-cids.json');
$popularCids = [];

if (is_file($cidFile)) {
    $json = json_decode((string) file_get_contents($cidFile), true);

    if (is_array($json)) {
        foreach ($json as $cid) {
            $cid = (int) $cid;

            if ($cid > 0) {
                $popularCids[$cid] = true;
            }
        }
    }
}

$allCounts = [];

$allRows = DB::table('anime')
    ->leftJoin('episodes', 'episodes.anime_id', '=', 'anime.id')
    ->whereNotNull('anime.cat_id')
    ->selectRaw('anime.cat_id AS cat_id, COUNT(episodes.id) AS episode_count')
    ->groupBy('anime.cat_id')
    ->get();

foreach ($allRows as $row) {
    $cat = (int) $row->cat_id;

    if ($cat > 0) {
        $allCounts[$cat] = ($allCounts[$cat] ?? 0) + (int) $row->episode_count;
    }
}

$missing = [];

foreach (array_keys($popularCids) as $cid) {
    if (($allCounts[$cid] ?? 0) === 0) {
        $missing[] = $cid;
    }
}

sort($missing);

printf("popular_cid_total=%d\n", count($popularCids));
printf("popular_cid_zero_episode=%d\n", count($missing));

if ($missing !== []) {
    printf("popular_cid_zero_sample=%s\n", implode(',', array_slice($missing, 0, 100)));
} else {
    echo "popular_cid_zero_sample=-\n";
}

echo "\n--- TOP 15 SERIES BY EPISODES ---\n";

$top = DB::table('episodes')
    ->join('anime', 'anime.id', '=', 'episodes.anime_id')
    ->selectRaw('anime.id, anime.title, anime.cat_id, COUNT(*) AS episode_count, MAX(episodes.number) AS max_number')
    ->groupBy('anime.id', 'anime.title', 'anime.cat_id')
    ->orderByDesc('episode_count')
    ->limit(15)
    ->get();

foreach ($top as $row) {
    printf(
        "%d | cat=%s | eps=%d | max=%s | %s\n",
        (int) $row->id,
        (string) ($row->cat_id ?? '-'),
        (int) $row->episode_count,
        (string) $row->max_number,
        substr((string) $row->title, 0, 75)
    );
}

echo "\n--- ANIME WITHOUT EPISODES SAMPLE ---\n";

$emptyAnime = DB::table('anime')
    ->leftJoin('episodes', 'episodes.anime_id', '=', 'anime.id')
    ->selectRaw('anime.id, anime.title, anime.cat_id, anime.source, anime.external_id, COUNT(episodes.id) AS episode_count')
    ->groupBy('anime.id', 'anime.title', 'anime.cat_id', 'anime.source', 'anime.external_id')
    ->havingRaw('COUNT(episodes.id) = 0')
    ->orderByDesc('anime.view_count')
    ->limit(20)
    ->get();

foreach ($emptyAnime as $row) {
    printf(
        "%d | cat=%s | source=%s | ext=%s | %s\n",
        (int) $row->id,
        (string) ($row->cat_id ?? '-'),
        (string) ($row->source ?? '-'),
        substr((string) ($row->external_id ?? '-'), 0, 35),
        substr((string) $row->title, 0, 75)
    );
}

echo "\n";

if ($episodeWithoutStream === 0) {
    echo "FINAL_STREAM_AUDIT=OK\n";
} else {
    echo "FINAL_STREAM_AUDIT=FAIL\n";
}
