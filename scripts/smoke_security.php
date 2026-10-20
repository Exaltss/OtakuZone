<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/smoke_security.php
 * Fungsi : smoke test offline untuk TokenService, IpHasher, AuditWriter, QualityResolver, AdmissionControl
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §4.8, §4.10, §9.2
 */
declare(strict_types=1);

use App\Models\AuditLog;
use App\Services\Security\AuditWriter;
use App\Services\Security\IpHasher;
use App\Services\Security\LogSanitizer;
use App\Services\Stream\AdmissionControl;
use App\Services\Stream\QualityResolver;
use App\Services\Stream\TokenService;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fail = function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$hasher = app(IpHasher::class);

$a = $hasher->hashIpPrefix('203.0.113.5');
$b = $hasher->hashIpPrefix('203.0.113.99');
$c = $hasher->hashIpPrefix('198.51.100.5');

if ($a !== $b) {
    $fail('IP prefix /24 tidak stabil untuk subnet sama');
}

if ($a === $c) {
    $fail('IP prefix /24 bocor ke subnet berbeda');
}

if ($hasher->hashUa("Bad\r\nUA  test") !== $hasher->hashUa('Bad UA test')) {
    $fail('UA sanitizer tidak menstabilkan newline/spasi');
}

$tokens = app(TokenService::class);

$nonce = $tokens->issueNonce(1, 'sess-test-' . bin2hex(random_bytes(6)));

if (!$tokens->consumeNonce($nonce, 1, 'sess-test-' . explode('sess-test-', 'sess-test-' . substr($nonce, 0, 0))[0] ?? '')) {
    // fallback: issue again for deterministic session below
}

$sessionId = 'sess-' . bin2hex(random_bytes(8));
$nonce = $tokens->issueNonce(1, $sessionId);

if (!$tokens->consumeNonce($nonce, 1, $sessionId)) {
    $fail('nonce valid ditolak');
}

if ($tokens->consumeNonce($nonce, 1, $sessionId)) {
    $fail('nonce sekali pakai bisa dipakai dua kali');
}

$nonce2 = $tokens->issueNonce(1, $sessionId);

if ($tokens->consumeNonce($nonce2, 2, $sessionId)) {
    $fail('nonce lintas user diterima');
}

$token = $tokens->issuePlayToken(10, '720', 1, '203.0.113.5', 'Mozilla/5.0 Test', 60);
$verify = $tokens->verifyPlayToken($token, 1, '203.0.113.99', 'Mozilla/5.0 Test');

if (($verify['ok'] ?? false) !== true) {
    $fail('PLAY token valid ditolak: ' . (string) ($verify['reason'] ?? 'unknown'));
}

if (($verify['payload']['eid'] ?? null) !== 10) {
    $fail('PLAY token payload eid salah');
}

$wrongUid = $tokens->verifyPlayToken($token, 2, '203.0.113.99', 'Mozilla/5.0 Test');

if (($wrongUid['ok'] ?? false) !== false || ($wrongUid['reason'] ?? '') !== 'uid') {
    $fail('PLAY token lintas UID harus ditolak');
}

$wrongIp = $tokens->verifyPlayToken($token, 1, '198.51.100.5', 'Mozilla/5.0 Test');

if (($wrongIp['ok'] ?? false) !== false || ($wrongIp['reason'] ?? '') !== 'ip') {
    $fail('PLAY token lintas IP prefix harus ditolak');
}

$wrongUa = $tokens->verifyPlayToken($token, 1, '203.0.113.99', 'Different UA');

if (($wrongUa['ok'] ?? false) !== false || ($wrongUa['reason'] ?? '') !== 'ua') {
    $fail('PLAY token lintas UA harus ditolak');
}

$expired = $tokens->issuePlayToken(10, '720', 1, '203.0.113.5', 'Mozilla/5.0 Test', -1);
$verifyExpired = $tokens->verifyPlayToken($expired, 1, '203.0.113.5', 'Mozilla/5.0 Test');

if (($verifyExpired['ok'] ?? false) !== false || ($verifyExpired['reason'] ?? '') !== 'expired') {
    $fail('PLAY token expired harus ditolak');
}

$invalid = $tokens->verifyPlayToken('not-a-token', 1, '203.0.113.5', 'Mozilla/5.0 Test');

if (($invalid['ok'] ?? false) !== false) {
    $fail('PLAY token invalid harus ditolak');
}

$quality = app(QualityResolver::class);

$resolved = $quality->resolve(['360' => 'x', '720' => 'y'], '1080');

if ($resolved !== '720') {
    $fail('fallback 1080->720 gagal');
}

$resolved2 = $quality->resolve(['360' => 'x'], '480');

if ($resolved2 !== '360') {
    $fail('fallback 480->360 gagal');
}

$resolved3 = $quality->resolve(['1080' => 'x', '720' => 'y'], '360');

if ($resolved3 !== '720') {
    $fail('fallback naik dari 360 ke kualitas terdekat gagal');
}

if ($quality->resolve([], '720') !== null) {
    $fail('quality kosong harus null');
}

$admission = AdmissionControl::make();

if ($admission->allow(1) !== true) {
    $fail('admission harus degradasi lolos saat Redis tidak tersedia');
}

$admission->register(1, 'smoke-member');
$admission->release(1, 'smoke-member');

$writer = app(AuditWriter::class);

DB::beginTransaction();

$writer->write('smoke.test', 1, 'Anime', 1, [
    'url' => 'https://secret.example',
    'token' => 'abc123',
    'safe' => 'ok',
]);

$log = AuditLog::query()
    ->where('action', 'smoke.test')
    ->latest('id')
    ->first();

if ($log === null) {
    DB::rollBack();
    $fail('audit log tidak tertulis');
}

$meta = $log->meta ?? [];

if (array_key_exists('url', $meta)) {
    DB::rollBack();
    $fail('meta.url tidak di-redact');
}

if (($meta['token'] ?? null) !== '[REDACTED]') {
    DB::rollBack();
    $fail('meta.token tidak di-redact');
}

if (($meta['safe'] ?? null) !== 'ok') {
    DB::rollBack();
    $fail('meta.safe ikut terhapus');
}

DB::rollBack();

$sanitizer = new LogSanitizer();
$record = [
    'context' => [
        'url' => 'https://x',
        'api_key' => 'secret',
        'safe' => 'ok',
    ],
    'extra' => [
        'nonce' => 'n',
    ],
];

$out = $sanitizer($record);

if (array_key_exists('url', $out['context'])) {
    $fail('LogSanitizer tidak meredact context.url');
}

if (($out['context']['api_key'] ?? null) !== '[REDACTED]') {
    $fail('LogSanitizer tidak meredact context.api_key');
}

if (($out['extra']['nonce'] ?? null) !== '[REDACTED]') {
    $fail('LogSanitizer tidak meredact extra.nonce');
}

if (($out['context']['safe'] ?? null) !== 'ok') {
    $fail('LogSanitizer merusak field aman');
}

echo "OK: security core offline smoke lulus.\n";
