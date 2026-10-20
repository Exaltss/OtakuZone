<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/audit_detail_sweep.php
 * Fungsi : audit hasil sweep episode per seri, fokus Detective Conan
 * Rujuk  : Blueprint_ANIMEKU.md §4, §6
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$strict = in_array('--strict', $argv ?? [], true);

$animeTotal = (int) DB::table('anime')->count();
$episodeTotal = (int) DB::table('episodes')->count();
$episodeWithStream = (int) DB::table('episodes')
    ->whereNotNull('quality_urls')
    ->where('quality_urls', '!=', '')
    ->count();
$animeWithEpisodes = (int) DB::table('episodes')->count(DB::raw('DISTINCT anime_id'));
$animeWithoutEpisodes = $animeTotal - $animeWithEpisodes;
$maxNumber = DB::table('episodes')->max('number');
$gt999 = (int) DB::table('episodes')->where('number', '>', 999)->count();

echo "--- GLOBAL ---\n";
printf("anime_total=%d\n", $animeTotal);
printf("episodes_total=%d\n", $episodeTotal);
printf("episodes_with_stream=%d\n", $episodeWithStream);
printf("anime_with_episodes=%d\n", $animeWithEpisodes);
printf("anime_without_episodes=%d\n", $animeWithoutEpisodes);
printf("max_episode_number=%s\n", (string) ($maxNumber ?? '0'));
printf("episodes_gt_999=%d\n", $gt999);

echo "\n--- CAT 56 DETECTIVE CONAN ---\n";

$conan = DB::table('anime')
    ->where('cat_id', 56)
    ->first(['id', 'title', 'slug', 'episodes_total']);

$conanEpisodes = 0;
$conanMax = 0.0;
$conanWithStream = 0;
$conanWithoutStream = 0;

if ($conan === null) {
    echo "conan=missing\n";

    if ($strict) {
        fwrite(STDERR, "FAIL: Detective Conan cat_id=56 tidak ditemukan\n");
        exit(1);
    }
} else {
    $rows = DB::table('episodes')
        ->where('anime_id', $conan->id)
        ->selectRaw('COUNT(*) AS cnt, MAX(number) AS max_ep, SUM(CASE WHEN quality_urls IS NOT NULL AND quality_urls != "" THEN 1 ELSE 0 END) AS with_stream')
        ->first();

    $conanEpisodes = (int) ($rows->cnt ?? 0);
    $conanMax = (float) ($rows->max_ep ?? 0);
    $conanWithStream = (int) ($rows->with_stream ?? 0);
    $conanWithoutStream = (int) DB::table('episodes')
        ->where('anime_id', $conan->id)
        ->where(static function ($q): void {
            $q->whereNull('quality_urls')
                ->orWhere('quality_urls', '=', '');
        })
        ->count();

    printf("conan_anime_id=%d\n", (int) $conan->id);
    printf("conan_title=%s\n", (string) $conan->title);
    printf("conan_episodes_db=%d\n", $conanEpisodes);
    printf("conan_episodes_total_col=%d\n", (int) ($conan->episodes_total ?? 0));
    printf("conan_max_ep=%s\n", (string) $conanMax);
    printf("conan_with_stream=%d\n", $conanWithStream);
    printf("conan_without_stream=%d\n", $conanWithoutStream);
}

echo "\n--- TOP 10 SERIES BY EPISODES ---\n";

$top = DB::table('episodes')
    ->join('anime', 'anime.id', '=', 'episodes.anime_id')
    ->selectRaw('anime.id, anime.title, anime.cat_id, COUNT(*) AS episode_count, MAX(episodes.number) AS max_number')
    ->groupBy('anime.id', 'anime.title', 'anime.cat_id')
    ->orderByDesc('episode_count')
    ->limit(10)
    ->get();

foreach ($top as $row) {
    printf(
        "%d | cat=%s | eps=%d | max=%s | %s\n",
        (int) $row->id,
        (string) ($row->cat_id ?? '-'),
        (int) $row->episode_count,
        (string) $row->max_number,
        substr((string) $row->title, 0, 70)
    );
}

if ($strict) {
    if ($conanEpisodes < 1200) {
        fwrite(STDERR, "FAIL: Detective Conan episode count kurang: {$conanEpisodes}\n");
        exit(1);
    }

    if ($conanMax < 1217) {
        fwrite(STDERR, "FAIL: Detective Conan max episode kurang: {$conanMax}\n");
        exit(1);
    }

    if ($conanWithoutStream > 0) {
        fwrite(STDERR, "FAIL: Detective Conan punya episode tanpa stream: {$conanWithoutStream}\n");
        exit(1);
    }

    echo "\nSTRICT_AUDIT=OK\n";
}
