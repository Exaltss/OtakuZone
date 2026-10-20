<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_sync.php
 * Fungsi : smoke offline sync pipeline dengan Http::fake dan rollback DB
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
    'zonaotaku.sync.throttle_ms' => 0,
    'zonaotaku.sync.jitter_ms' => 0,
]);

$makeItem = static function (int $vid, string $videoId, string $title): array {
    return [
        'vid' => $vid,
        'cat_id' => 7,
        'video_title' => $title,
        'video_id' => $videoId,
        'video_thumbnail' => 'smoke.jpg',
        'video_duration' => '24 Menit',
        'video_description' => '<p>Sinopsis smoke. Link https://example.com/secret</p>',
        'video_url' => 'http://santol.whatbox.ca:29318/smoke-360.mp4',
        'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/smoke-480.mp4',
        'video_url_hd' => 'http://greip.whatbox.ca:29318/smoke-720.mp4',
        'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/smoke-1080.mp4',
    ];
};

$items = [
    $makeItem(9001, 'smoke-ep-1', 'Smoke Sync Eps 01'),
    $makeItem(9001, 'smoke-ep-2', 'Smoke Sync Eps 02'),
];

Http::fake(function ($request) use ($items) {
    $url = (string) $request->url();
    $parsed = parse_url($url);
    $query = [];
    parse_str((string) ($parsed['query'] ?? ''), $query);

    $page = (int) ($query['page'] ?? 1);

    if (str_contains($url, 'get_category_popular')) {
        return Http::response([
            'status' => 'ok',
            'latest_anime' => $page === 1 ? $items : [],
        ]);
    }

    if (str_contains($url, 'get_category_genre')) {
        return Http::response([
            'status' => 'ok',
            'latest_anime' => $page === 1 ? $items : [],
        ]);
    }

    return Http::response([
        'status' => 'ok',
        'latest_anime' => $page === 1 ? $items : [],
    ]);
});

$code = Artisan::call('sync:latest', [
    '--pages' => 1,
    '--count' => 2,
    '--reset' => true,
]);

$output = Artisan::output();

if ($code !== 0) {
    $fail("sync:latest exit {$code}: {$output}");
}

if (Anime::query()->count() !== 1) {
    $fail('sync:latest tidak menghasilkan exactly 1 anime');
}

if (Episode::query()->count() !== 2) {
    $fail('sync:latest tidak menghasilkan exactly 2 episode');
}

$anime = Anime::query()->first();

if ($anime === null) {
    $fail('anime smoke tidak ditemukan');
}

$episode = Episode::query()->where('number', 2.0)->first();

if ($episode === null) {
    $fail('episode number 2 tidak ditemukan');
}

$quality = $episode->quality_urls;

if (!is_array($quality)) {
    $fail('quality_urls tidak ter-decrypt sebagai array');
}

if (($quality['720'] ?? null) !== 'http://greip.whatbox.ca:29318/smoke-720.mp4') {
    $fail('quality 720 salah setelah encrypt/decrypt');
}

$rawJson = json_encode($anime->raw_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($rawJson === false) {
    $fail('raw_meta gagal di-json saat smoke sync');
}

if (str_contains($rawJson, 'http://') || str_contains($rawJson, 'https://')) {
    $fail('raw_meta masih menyimpan URL mentah setelah sync');
}

$code = Artisan::call('sync:popular', [
    '--pages' => 1,
    '--count' => 2,
    '--reset' => true,
]);

$output = Artisan::output();

if ($code !== 0) {
    $fail("sync:popular exit {$code}: {$output}");
}

$anime->refresh();

if ((int) $anime->popularity_score <= 0) {
    $fail('popularity_score tidak terisi oleh sync:popular');
}

$code = Artisan::call('probe:upstream');
$output = Artisan::output();

if ($code !== 0) {
    $fail("probe:upstream exit {$code}: {$output}");
}

DB::rollBack();

echo "OK: sync pipeline offline smoke lulus.\n";
