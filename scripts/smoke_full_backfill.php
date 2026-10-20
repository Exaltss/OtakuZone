<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_full_backfill.php
 * Fungsi : smoke offline full backfill grouping seri + episode + encrypt quality
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.4, §13.5
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

$items = [
    [
        'vid' => 7001,
        'cat_id' => 7,
        'video_title' => 'Smoke Full Series Eps 01',
        'video_id' => 'smoke-full-ep-1',
        'video_thumbnail' => 'smoke.jpg',
        'video_duration' => '24 Menit',
        'video_description' => '<p>Sinopsis smoke full. Link https://secret.example/path</p>',
        'video_url' => 'http://santol.whatbox.ca:29318/smoke-360.mp4',
        'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/smoke-480.mp4',
        'video_url_hd' => 'http://greip.whatbox.ca:29318/smoke-720.mp4',
        'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/smoke-1080.mp4',
    ],
    [
        'vid' => 7002,
        'cat_id' => 7,
        'video_title' => 'Smoke Full Series Eps 02',
        'video_id' => 'smoke-full-ep-2',
        'video_thumbnail' => 'smoke.jpg',
        'video_duration' => '24 Menit',
        'video_description' => '<p>Sinopsis smoke full. Link https://secret.example/path</p>',
        'video_url' => 'http://santol.whatbox.ca:29318/smoke-360b.mp4',
        'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/smoke-480b.mp4',
        'video_url_hd' => 'http://greip.whatbox.ca:29318/smoke-720b.mp4',
        'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/smoke-1080b.mp4',
    ],
];

Http::fake(function ($request) use ($items) {
    $url = (string) $request->url();

    if (str_contains($url, 'get_videos')) {
        return Http::response([
            'status' => 'ok',
            'pages' => 1,
            'count_total' => count($items),
            'latest_anime' => $items,
        ]);
    }

    return Http::response(['status' => 'ok', 'latest_anime' => []]);
});

$code = Artisan::call('sync:full', [
    '--pages' => 1,
    '--count' => 2,
    '--reset' => true,
    '--no-checkpoint' => true,
    '--throttle' => 0,
    '--jitter' => 0,
]);

$output = Artisan::output();

if ($code !== 0) {
    $fail("sync:full exit {$code}: {$output}");
}

if (preg_match('/\bError\b|\bFatal error\b|Call to a member function|Uncaught/i', $output)) {
    $fail("sync:full contains runtime error: {$output}");
}

if (Anime::query()->count() !== 1) {
    $fail('sync:full grouping gagal: jumlah anime bukan 1');
}

if (Episode::query()->count() !== 2) {
    $fail('sync:full grouping gagal: jumlah episode bukan 2');
}

$anime = Anime::query()->first();

if ($anime === null) {
    $fail('anime smoke full tidak ditemukan');
}

if ($anime->title !== 'Smoke Full Series') {
    $fail('series title salah: ' . (string) $anime->title);
}

if ((int) $anime->episodes_total !== 2) {
    $fail('episodes_total tidak terisi 2');
}

$ep1 = Episode::query()->where('number', 1.0)->first();
$ep2 = Episode::query()->where('number', 2.0)->first();

if ($ep1 === null || $ep2 === null) {
    $fail('episode number 1/2 tidak ditemukan');
}

$quality = $ep2->quality_urls;

if (!is_array($quality)) {
    $fail('quality_urls tidak ter-decrypt sebagai array');
}

if (($quality['720'] ?? null) !== 'http://greip.whatbox.ca:29318/smoke-720b.mp4') {
    $fail('quality 720 episode 2 salah');
}

$rawJson = json_encode($anime->raw_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($rawJson === false) {
    $fail('raw_meta smoke full gagal di-json');
}

if (str_contains($rawJson, 'http://') || str_contains($rawJson, 'https://')) {
    $fail('raw_meta smoke full masih menyimpan URL mentah');
}

DB::rollBack();

echo "OK: full backfill offline smoke lulus.\n";
