<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_episodes.php
 * Fungsi : smoke offline episode >999 + stream encrypt/decrypt tanpa jaringan asli
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.4, Blueprint_ANIMEKU §4, §6
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

Http::fake(function ($request) {
    $url = (string) $request->url();

    if (str_contains($url, 'get_videos')) {
        return Http::response([
            'status' => 'ok',
            'count' => 1,
            'count_total' => 1,
            'pages' => 1,
            'latest_anime' => [
                [
                    'vid' => 8888,
                    'cat_id' => 1,
                    'video_title' => 'Detective Conan Eps 1217',
                    'video_id' => 'smoke-ep-1217',
                    'video_thumbnail' => 'smoke.jpg',
                    'video_duration' => '24 Menit',
                    'video_description' => '<p>Sinopsis smoke. Link https://secret.example/path</p>',
                    'video_url' => 'http://santol.whatbox.ca:29318/smoke-360.mp4',
                    'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/smoke-480.mp4',
                    'video_url_hd' => 'http://greip.whatbox.ca:29318/smoke-720.mp4',
                    'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/smoke-1080.mp4',
                ],
            ],
        ]);
    }

    return Http::response([
        'status' => 'ok',
        'latest_anime' => [],
    ]);
});

$code = Artisan::call('sync:episodes', [
    '--start' => 1,
    '--end' => 1,
    '--count' => 1,
    '--no-checkpoint' => true,
    '--throttle' => 0,
    '--jitter' => 0,
    '--empty-retries' => 1,
    '--request-retries' => 1,
    '--no-ansi' => true,
]);

$output = Artisan::output();

if ($code !== 0) {
    $fail("sync:episodes exit {$code}: {$output}");
}

if (preg_match('/\bError\b|\bFatal error\b|Call to a member function|Uncaught/i', $output)) {
    $fail("sync:episodes contains runtime error: {$output}");
}

$episode = Episode::query()->where('external_id', 'smoke-ep-1217')->first();

if ($episode === null) {
    $fail('episode smoke 1217 tidak tertulis');
}

if (abs((float) $episode->number - 1217.0) > 0.001) {
    $fail('episode number 1217 salah: ' . (string) $episode->number);
}

$quality = $episode->quality_urls;

if (!is_array($quality)) {
    $fail('quality_urls tidak ter-decrypt sebagai array');
}

if (($quality['1080'] ?? null) !== 'http://rambutan.whatbox.ca:29318/smoke-1080.mp4') {
    $fail('quality 1080 salah setelah encrypt/decrypt');
}

$anime = Anime::query()->find($episode->anime_id);

if ($anime === null) {
    $fail('anime induk episode smoke tidak ditemukan');
}

if ($anime->title !== 'Detective Conan') {
    $fail('series title salah: ' . (string) $anime->title);
}

$rawJson = json_encode($anime->raw_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($rawJson === false) {
    $fail('raw_meta smoke episode gagal di-json');
}

if (str_contains($rawJson, 'http://') || str_contains($rawJson, 'https://')) {
    $fail('raw_meta smoke episode masih menyimpan URL mentah');
}

DB::rollBack();

echo "OK: episode + stream offline smoke lulus.\n";
