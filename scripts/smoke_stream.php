<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_stream.php
 * Fungsi : smoke offline StreamProxy + TokenService + guard MP4 tanpa jaringan asli
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §4.3, §13.5
 */
declare(strict_types=1);

use App\Models\Anime;
use App\Models\Episode;
use App\Services\Stream\StreamProxy;
use App\Services\Stream\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fail = function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

DB::beginTransaction();

$rand = bin2hex(random_bytes(6));

$anime = Anime::create([
    'external_id' => 'smoke-anime-' . $rand,
    'cat_id' => 1,
    'title' => 'Smoke Proxy Anime',
    'slug' => 'smoke-proxy-anime-' . $rand,
    'source' => 'animeku',
    'synced_at' => now(),
]);

$episode = Episode::create([
    'anime_id' => $anime->id,
    'external_id' => 'smoke-ep-' . $rand,
    'number' => 1,
    'title' => 'Smoke Proxy Episode',
    'quality_urls' => [
        '720' => 'http://greip.whatbox.ca:29318/smoke.mp4',
    ],
    'synced_at' => now(),
]);

$tokens = app(TokenService::class);
$proxy = app(StreamProxy::class);

$token = $tokens->issuePlayToken(
    (int) $episode->id,
    '720',
    1,
    '203.0.113.5',
    'Smoke Stream UA',
    60
);

$resolved = $proxy->resolve($token, '203.0.113.99', 'Smoke Stream UA');

if (($resolved['ok'] ?? false) !== true) {
    DB::rollBack();
    $fail('proxy resolve token valid ditolak: ' . json_encode($resolved, JSON_UNESCAPED_SLASHES));
}

if (($resolved['url'] ?? null) !== 'http://greip.whatbox.ca:29318/smoke.mp4') {
    DB::rollBack();
    $fail('proxy resolve URL salah');
}

$wrongIp = $proxy->resolve($token, '198.51.100.5', 'Smoke Stream UA');

if (($wrongIp['ok'] ?? false) !== false || ($wrongIp['code'] ?? '') !== 'token_ip') {
    DB::rollBack();
    $fail('proxy resolve IP salah harus token_ip');
}

$wrongUa = $proxy->resolve($token, '203.0.113.99', 'Different Smoke UA');

if (($wrongUa['ok'] ?? false) !== false || ($wrongUa['code'] ?? '') !== 'token_ua') {
    DB::rollBack();
    $fail('proxy resolve UA salah harus token_ua');
}

$mp4Head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

Http::fake([
    'http://greip.whatbox.ca:29318/*' => Http::response($mp4Head, 206, [
        'Content-Type' => 'video/mp4',
        'Content-Length' => '1000',
        'Content-Range' => 'bytes 0-23/1000',
    ]),
    'http://bad.whatbox.ca:29318/*' => Http::response('not-mp4', 206, [
        'Content-Type' => 'video/mp4',
        'Content-Length' => '7',
        'Content-Range' => 'bytes 0-6/7',
    ]),
    '*' => Http::response('network_blocked', 599),
]);

$upstream = $proxy->openUpstream('http://greip.whatbox.ca:29318/smoke.mp4', 'bytes=0-999', 0);

if (($upstream['ok'] ?? false) !== true) {
    DB::rollBack();
    $fail('openUpstream MP4 valid ditolak: ' . json_encode($upstream, JSON_UNESCAPED_SLASHES));
}

if (($upstream['content_type'] ?? null) !== 'video/mp4') {
    DB::rollBack();
    $fail('openUpstream content-type salah');
}

$bad = $proxy->openUpstream('http://bad.whatbox.ca:29318/smoke.mp4', 'bytes=0-999', 0);

if (($bad['ok'] ?? false) !== false || ($bad['code'] ?? '') !== 'guard_fail') {
    DB::rollBack();
    $fail('openUpstream non-MP4 harus guard_fail');
}

DB::rollBack();

echo "OK: stream proxy offline smoke lulus.\n";
