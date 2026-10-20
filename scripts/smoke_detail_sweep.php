<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_detail_sweep.php
 * Fungsi : smoke offline detail sweep dengan episode identity vid
 * Rujuk  : Blueprint_ANIMEKU.md §3.2, §3.3, §4; metadata turunan user
 */
declare(strict_types=1);

use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

DB::beginTransaction();

$fail = function (string $message): never {
    DB::rollBack();
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

config([
    'zonaotaku.animeku.throttle_ms' => 0,
    'zonaotaku.animeku.jitter_ms' => 0,
]);

$smokeCatIds = [99000056, 990000999];
$smokeEpisodeVids = ['99001', '99002', '99100'];

$animeIds = DB::table('anime')
    ->whereIn('cat_id', $smokeCatIds)
    ->pluck('id')
    ->all();

if ($animeIds !== []) {
    DB::table('episodes')->whereIn('anime_id', $animeIds)->delete();
    DB::table('anime')->whereIn('id', $animeIds)->delete();
}

DB::table('episodes')->whereIn('external_id', $smokeEpisodeVids)->delete();

$conan1217 = [
    'vid' => 99001,
    'cat_id' => 99000056,
    'video_title' => 'Detective Conan Smoke Eps 1217',
    'video_id' => 'smoke-conan-1217',
    'category_name' => 'Detective Conan Smoke',
    'ep' => 1217.0,
    'video_thumbnail' => 'conan.jpg',
    'video_duration' => '24 Menit',
    'video_description' => '<p>Sinopsis Conan. Link https://secret.example/conan</p>',
    'video_url' => 'http://santol.whatbox.ca:29318/conan-360.mp4',
    'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/conan-480.mp4',
    'video_url_hd' => 'http://greip.whatbox.ca:29318/conan-720.mp4',
    'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/conan-1080.mp4',
    'rating' => 8.7,
    'year' => 2026,
    'total_views' => 123456,
];

$conan01 = $conan1217;
$conan01['vid'] = 99002;
$conan01['video_title'] = 'Detective Conan Smoke Eps 01';
$conan01['video_id'] = 'smoke-conan-01';
$conan01['ep'] = 1.0;
$conan01['video_url_hd'] = 'http://greip.whatbox.ca:29318/conan-720-ep01.mp4';

$movie = [
    'vid' => 99100,
    'cat_id' => 990000999,
    'video_title' => 'Smoke Movie Title',
    'video_id' => 'smoke-movie-1',
    'category_name' => 'Smoke Movie Title',
    'video_thumbnail' => 'movie.jpg',
    'video_duration' => 'OnGoing',
    'video_description' => '<p>Sinopsis movie. Link https://secret.example/movie</p><p>Aired: Oct 7, 2026 to ?</p><p>Duration: 90 min.</p>',
    'video_url' => 'http://santol.whatbox.ca:29318/movie-360.mp4',
    'video_url_hd' => 'http://greip.whatbox.ca:29318/movie-720.mp4',
    'rating' => 7.5,
    'year' => 2026,
    'total_views' => 777,
];

Http::fake(function ($request) use ($conan1217, $conan01, $movie) {
    $url = (string) $request->url();
    $query = [];
    parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);

    if (str_contains($url, 'get_anime_detail')) {
        $id = (string) ($query['id'] ?? '');

        if ($id === '99000056') {
            return Http::response([
                'status' => 'ok',
                'suggested' => [$conan1217, $conan01],
            ]);
        }

        if ($id === '990000999') {
            return Http::response([
                'status' => 'ok',
                'suggested' => [$movie],
            ]);
        }
    }

    return Http::response(['status' => 'error'], 599);
});

$code = Artisan::call('sync:detail-sweep', [
    '--seed-cids' => '99000056,990000999',
    '--limit-cids' => 2,
    '--throttle' => 0,
    '--jitter' => 0,
    '--progress' => 1,
    '--no-checkpoint' => true,
    '--no-ansi' => true,
]);

$output = Artisan::output();

if ($code !== 0) {
    $fail("sync:detail-sweep exit {$code}: {$output}");
}

if (preg_match('/\bError\b|\bFatal error\b|Call to a member function|Uncaught/i', $output)) {
    $fail("sync:detail-sweep contains runtime error: {$output}");
}

$conanAnime = Anime::query()->where('cat_id', 99000056)->first();
$movieAnime = Anime::query()->where('cat_id', 990000999)->first();

if ($conanAnime === null || $movieAnime === null) {
    $fail('anime smoke cat_id 99000056/990000999 tidak ditemukan');
}

if ($conanAnime->title !== 'Detective Conan Smoke') {
    $fail('Detective Conan Smoke title salah: ' . (string) $conanAnime->title);
}

if ($movieAnime->title !== 'Smoke Movie Title') {
    $fail('Smoke Movie title salah: ' . (string) $movieAnime->title);
}

$ep1217 = Episode::query()->where('external_id', '99001')->first();
$ep01 = Episode::query()->where('external_id', '99002')->first();
$epMovie = Episode::query()->where('external_id', '99100')->first();

if ($ep1217 === null || $ep01 === null || $epMovie === null) {
    $fail('salah satu episode smoke vid tidak tertulis');
}

if (abs((float) $ep1217->number - 1217.0) > 0.001) {
    $fail('Conan Eps 1217 number salah: ' . (string) $ep1217->number);
}

if (abs((float) $ep01->number - 1.0) > 0.001) {
    $fail('Conan Eps 01 number salah: ' . (string) $ep01->number);
}

if (abs((float) $epMovie->number - 1.0) > 0.001) {
    $fail('Smoke Movie number salah: ' . (string) $epMovie->number);
}

$quality1217 = $ep1217->quality_urls;

if (!is_array($quality1217)) {
    $fail('Conan quality_urls tidak ter-decrypt sebagai array');
}

if (($quality1217['1080'] ?? null) !== 'http://rambutan.whatbox.ca:29318/conan-1080.mp4') {
    $fail('Conan quality 1080 salah');
}

if (($quality1217['720'] ?? null) !== 'http://greip.whatbox.ca:29318/conan-720.mp4') {
    $fail('Conan quality 720 salah');
}

$qualityMovie = $epMovie->quality_urls;

if (!is_array($qualityMovie)) {
    $fail('Movie quality_urls tidak ter-decrypt sebagai array');
}

if (($qualityMovie['720'] ?? null) !== 'http://greip.whatbox.ca:29318/movie-720.mp4') {
    $fail('Movie quality 720 salah');
}

$rawJson = json_encode([$conanAnime->raw_meta, $movieAnime->raw_meta], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($rawJson === false) {
    $fail('raw_meta smoke detail gagal di-json');
}

if (str_contains($rawJson, 'http://') || str_contains($rawJson, 'https://')) {
    $fail('raw_meta smoke detail masih menyimpan URL mentah');
}

DB::rollBack();

echo "OK: vid-aligned detail sweep offline smoke lulus.\n";
