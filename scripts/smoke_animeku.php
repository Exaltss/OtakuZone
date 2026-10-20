<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_animeku.php
 * Fungsi : smoke test offline untuk Animeku services tanpa hit jaringan
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2, §5.3, §13.5
 */
declare(strict_types=1);

use App\Services\Animeku\CircuitBreaker;
use App\Services\Animeku\ResolverChain;
use App\Services\Animeku\ResponseMapper;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fail = function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$mapper = app(ResponseMapper::class);

$item = [
    'vid' => 123,
    'cat_id' => 1,
    'video_title' => 'Daxia Shou Mu Ren Eps 06',
    'video_url' => 'http://santol.whatbox.ca:29318/a.mp4',
    'video_url_minihd' => 'http://honeydew.whatbox.ca:29318/b.mp4',
    'video_url_hd' => 'http://greip.whatbox.ca:29318/c.mp4',
    'video_url_fullhd' => 'http://rambutan.whatbox.ca:29318/d.mp4',
    'video_id' => 'cda11up',
    'video_thumbnail' => 'thumb.jpg',
    'video_duration' => 'OnGoing',
    'video_description' => '<p>Sinopsis uji. Tonton di https://example.com/secret.</p>',
];

$mapped = $mapper->mapItem($item);

if (($mapped['anime']['external_id'] ?? null) !== '123') {
    $fail('anime.external_id salah');
}

if (($mapped['anime']['slug'] ?? null) !== 'daxia-shou-mu-ren-eps-06') {
    $fail('anime.slug salah');
}

if (($mapped['episode']['number'] ?? null) !== 6.0) {
    $fail('episode.number salah');
}

$quality = $mapped['episode']['quality_urls'] ?? [];

if (!is_array($quality)) {
    $fail('quality_urls bukan array');
}

foreach (['360', '480', '720', '1080'] as $q) {
    if (!isset($quality[$q]) || !is_string($quality[$q])) {
        $fail("quality {$q} hilang");
    }
}

$rawJson = json_encode($mapped['anime']['raw_meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($rawJson === false) {
    $fail('raw_meta gagal di-json');
}

if (str_contains($rawJson, 'http://') || str_contains($rawJson, 'https://')) {
    $fail('raw_meta masih menyimpan URL mentah');
}

if (str_contains((string) ($mapped['anime']['synopsis'] ?? ''), 'https://example.com')) {
    $fail('synopsis masih menyimpan URL mentah');
}

$breaker = app(CircuitBreaker::class);
$smokeUrl = 'https://smoke-' . bin2hex(random_bytes(6)) . '.example.invalid/api';

for ($i = 0; $i < 5; $i++) {
    $breaker->failure($smokeUrl);
}

if ($breaker->allow($smokeUrl) !== false) {
    $fail('circuit breaker tidak OPEN setelah 5 failure');
}

$breaker->success($smokeUrl);

if ($breaker->allow($smokeUrl) !== true) {
    $fail('circuit breaker tidak reset setelah success');
}

$resolver = app(ResolverChain::class);
$urls = $resolver->urls();

if (!is_array($urls) || count($urls) < 1) {
    $fail('resolver chain kosong');
}

if (!str_starts_with((string) $urls[0], 'https://')) {
    $fail('resolver chain bukan https');
}

echo "OK: Animeku services offline smoke lulus.\n";
