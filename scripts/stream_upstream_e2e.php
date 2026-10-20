<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/stream_upstream_e2e.php
 * Fungsi : validasi terminal: episode DB -> decrypt quality_urls -> upstream Range -> magic MP4
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2, §4.3, §5; Blueprint_ANIMEKU.md §4, §5
 *
 * Usage:
 *   php scripts/stream_upstream_e2e.php [episode_id] [quality] [slug]
 *
 * Jika episode_id=0, script memilih episode terbaik yang punya URL whatbox valid.
 * Output aman: URL upstream tidak pernah dicetak penuh, hanya host + hash path.
 */
declare(strict_types=1);

use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$info = function (string $message): void {
    echo $message . "\n";
};

function decodeQuality(Episode $episode): array
{
    $value = $episode->quality_urls;

    if (is_array($value)) {
        return $value;
    }

    $raw = $episode->getRawOriginal('quality_urls');

    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    try {
        $plain = Crypt::decryptString($raw);
    } catch (\Throwable) {
        return [];
    }

    $decoded = json_decode($plain, true);

    return is_array($decoded) ? $decoded : [];
}

function validateUrl(string $url): array
{
    $out = [
        'ok' => false,
        'whatbox' => false,
        'host' => '',
        'scheme' => '',
        'path_hash' => '',
        'reason' => 'invalid_url',
    ];

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return $out;
    }

    $parts = parse_url($url);

    if ($parts === false) {
        return $out;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    $out['host'] = $host;
    $out['scheme'] = $scheme;
    $out['path_hash'] = substr(hash('sha256', $path), 0, 16);

    if (!in_array($scheme, ['http', 'https'], true)) {
        $out['reason'] = 'bad_scheme';
        return $out;
    }

    if ($host === '') {
        $out['reason'] = 'bad_host';
        return $out;
    }

    $out['ok'] = true;
    $out['whatbox'] = $host === 'whatbox.ca' || str_ends_with($host, '.whatbox.ca');

    if (!$out['whatbox']) {
        $out['reason'] = 'nonwhatbox';
    }

    return $out;
}

function qualityScore(string $key): int
{
    $k = strtolower(trim($key));

    if ($k === '1080' || str_contains($k, '1080') || str_contains($k, 'fullhd') || str_contains($k, 'fhd')) {
        return 100;
    }

    if ($k === '720' || str_contains($k, '720')) {
        return 90;
    }

    if ($k === '480' || str_contains($k, '480') || str_contains($k, 'mini')) {
        return 80;
    }

    if ($k === '360' || str_contains($k, '360')) {
        return 70;
    }

    if ($k === 'auto') {
        return 60;
    }

    if ($k === 'eng') {
        return 50;
    }

    if ($k === 'hdeng') {
        return 45;
    }

    if (str_contains($k, 'eng')) {
        return 40;
    }

    return 10;
}

$safeSnippet = function (string $body, int $limit = 96): string {
    $head = substr($body, 0, $limit);
    $printable = preg_replace('/[^\x20-\x7E]/', '.', $head) ?? '';

    return $printable . ($limit < strlen($body) ? '...' : '');
};

$hexHead = function (string $body, int $limit = 32): string {
    return strtoupper(bin2hex(substr($body, 0, $limit)));
};

$episodeId = (int) ($argv[1] ?? 0);
$qualityArg = trim((string) ($argv[2] ?? ''));
$animeSlug = trim((string) ($argv[3] ?? 'detective-conan'));

if ($episodeId > 0) {
    $episode = Episode::query()->find($episodeId);

    if ($episode === null) {
        $fail("Episode tidak ditemukan: id={$episodeId}");
    }
} else {
    if ($animeSlug === '') {
        $fail('episode_id=0 membutuhkan slug anime.');
    }

    $anime = Anime::query()->where('slug', $animeSlug)->first();

    if ($anime === null) {
        $fail("Anime tidak ditemukan: slug={$animeSlug}");
    }

    $bestEpisode = null;
    $bestScore = -1;
    $bestNumber = -1.0;

    Episode::query()
        ->where('anime_id', $anime->id)
        ->orderByDesc('number')
        ->chunkById(200, function ($eps) use (&$bestEpisode, &$bestScore, &$bestNumber): void {
            foreach ($eps as $ep) {
                $q = decodeQuality($ep);
                $scores = [];

                foreach ($q as $k => $v) {
                    if (!is_string($v) || $v === '') {
                        continue;
                    }

                    $val = validateUrl($v);

                    if (!$val['ok'] || !$val['whatbox']) {
                        continue;
                    }

                    $scores[(string) $k] = qualityScore((string) $k);
                }

                if ($scores === []) {
                    continue;
                }

                $score = max($scores);
                $number = (float) $ep->number;

                if ($score > $bestScore || ($score === $bestScore && $number > $bestNumber)) {
                    $bestEpisode = $ep;
                    $bestScore = $score;
                    $bestNumber = $number;
                }
            }
        });

    if ($bestEpisode === null) {
        $fail("Tidak ada episode dengan URL whatbox valid untuk slug={$animeSlug}. Jalankan audit_quality_urls.php.");
    }

    $episode = $bestEpisode;
}

$animeRow = Anime::query()->find($episode->anime_id);
$animeTitle = $animeRow === null ? '-' : (string) $animeRow->title;
$animeSlugOut = $animeRow === null ? '-' : (string) $animeRow->slug;

$info('--- TARGET ---');
printf(
    "anime_id=%d | cat_id=%s | slug=%s | title=%s\n",
    (int) ($animeRow->id ?? 0),
    (string) ($animeRow->cat_id ?? '-'),
    $animeSlugOut,
    mb_substr($animeTitle, 0, 70)
);
printf(
    "episode_id=%d | number=%s | title=%s\n",
    (int) $episode->id,
    number_format((float) $episode->number, 2, ',', '.'),
    mb_substr((string) ($episode->title ?? '-'), 0, 70)
);

$qualities = decodeQuality($episode);

if ($qualities === []) {
    $fail('quality_urls kosong atau bukan array.');
}

$usable = [];

foreach ($qualities as $key => $value) {
    if (!is_string($key) || !is_string($value) || $value === '') {
        continue;
    }

    $val = validateUrl($value);

    if (!$val['ok'] || !$val['whatbox']) {
        continue;
    }

    $usable[$key] = $value;
}

if ($usable === []) {
    $keys = [];

    foreach (array_keys($qualities) as $k) {
        $keys[] = (string) $k;
    }

    $fail('Tidak ada quality_urls yang lolos whitelist whatbox.ca. keys=' . implode(',', array_slice($keys, 0, 20)));
}

$available = array_keys($usable);

usort($available, static function (string $a, string $b): int {
    $sa = qualityScore($a);
    $sb = qualityScore($b);

    if ($sa !== $sb) {
        return $sb <=> $sa;
    }

    return strcmp($a, $b);
});

if ($qualityArg !== '' && isset($usable[$qualityArg])) {
    $selected = $qualityArg;
} else {
    $selected = $available[0];
}

$url = (string) $usable[$selected];
$meta = validateUrl($url);

if (!$meta['ok'] || !$meta['whatbox']) {
    $fail('URL terpilih gagal validasi whitelist.');
}

$info("\n--- QUALITY ---");
printf("selected_quality=%s\n", $selected);
printf("selected_score=%d\n", qualityScore($selected));
printf("available_keys=%s\n", implode(',', $available));
printf("upstream_host=%s\n", $meta['host']);
printf("upstream_scheme=%s\n", $meta['scheme']);
printf("upstream_path_hash=%s\n", $meta['path_hash']);

$headers = [
    'User-Agent: Dalvik/7.1.12.1.0 (com.newanimeku.animechanneldonghuasubindosubenglish U; Android ; 20175 Build/NMF260)',
    'Data-Agent: Your Videos Channel',
    'Accept: */*',
    'Referer: https://pencarinafkah.xyz/',
    'Origin: ',
];

$validateUpstreamUrl = function (string $u) use ($fail): array {
    $val = validateUrl($u);

    if (!$val['ok']) {
        $fail('URL upstream/effective tidak valid: ' . $val['reason']);
    }

    if (!$val['whatbox']) {
        $fail('URL upstream/effective di luar whitelist whatbox.ca: ' . $val['host']);
    }

    return $val;
};

$fetchRange = function (string $range, int $maxBytes) use ($url, $headers, $safeSnippet, $hexHead, $validateUpstreamUrl): array {
    if (!function_exists('curl_init')) {
        throw new \RuntimeException('Ekstensi PHP curl tidak tersedia.');
    }

    $body = '';
    $respHeaders = [];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RANGE => $range,
        CURLOPT_NOPROGRESS => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $len = strlen($line);

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($key))] = trim($value);
            }

            return $len;
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
            $body .= $chunk;

            if (strlen($body) >= $maxBytes) {
                return 0;
            }

            return strlen($chunk);
        },
    ]);

    curl_exec($ch);

    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    $effectiveMeta = $validateUpstreamUrl($effectiveUrl);

    return [
        'errno' => $errno,
        'error' => $error,
        'status' => $status,
        'content_type' => $contentType !== '' ? $contentType : (string) ($respHeaders['content-type'] ?? ''),
        'headers' => $respHeaders,
        'body' => $body,
        'length' => strlen($body),
        'effective_host' => $effectiveMeta['host'],
        'effective_path_hash' => $effectiveMeta['path_hash'],
        'snippet' => $safeSnippet($body),
        'hex_head' => $hexHead($body),
    ];
};

$assertRangeResult = function (array $r, string $label, int $minBytes) use ($fail, $info): void {
    if ($r['status'] !== 206) {
        $fail(sprintf(
            '%s upstream tidak menghormati Range: status=%d accept_ranges=%s content_range=%s curl_errno=%d error=%s snippet=%s',
            $label,
            $r['status'],
            (string) ($r['headers']['accept-ranges'] ?? '-'),
            (string) ($r['headers']['content-range'] ?? '-'),
            $r['errno'],
            $r['error'],
            $r['snippet']
        ));
    }

    if ($r['length'] < $minBytes) {
        $fail(sprintf(
            '%s body terlalu kecil: len=%d min=%d snippet=%s',
            $label,
            $r['length'],
            $minBytes,
            $r['snippet']
        ));
    }

    $head = substr((string) $r['body'], 0, 64);
    $hasFtyp = str_contains($head, 'ftyp');
    $hasMoov = str_contains($head, 'moov');
    $ct = strtolower((string) $r['content_type']);
    $looksVideo = str_contains($ct, 'video/') || str_contains($ct, 'octet-stream');

    if (!$hasFtyp && !$hasMoov && !$looksVideo) {
        $fail(sprintf(
            '%s bukan payload MP4 yang meyakinkan. content_type=%s hex_head=%s snippet=%s',
            $label,
            $r['content_type'],
            $r['hex_head'],
            $r['snippet']
        ));
    }

    $info(sprintf(
        "%s PASS status=%d len=%d content_type=%s accept_ranges=%s content_range=%s magic=%s host=%s path_hash=%s",
        $label,
        $r['status'],
        $r['length'],
        $r['content_type'] !== '' ? $r['content_type'] : '-',
        (string) ($r['headers']['accept-ranges'] ?? '-'),
        (string) ($r['headers']['content-range'] ?? '-'),
        $hasFtyp ? 'ftyp' : ($hasMoov ? 'moov' : 'content-type'),
        $r['effective_host'],
        $r['effective_path_hash']
    ));
};

$info("\n--- RANGE 1 ---");

try {
    $r1 = $fetchRange('0-65535', 65536);
} catch (\Throwable $e) {
    $fail('Range 1 exception: ' . $e->getMessage());
}

$assertRangeResult($r1, 'RANGE1', 32768);

$info("\n--- RANGE 2 ---");

try {
    $r2 = $fetchRange('65536-131071', 65536);
} catch (\Throwable $e) {
    $fail('Range 2 exception: ' . $e->getMessage());
}

$assertRangeResult($r2, 'RANGE2', 32768);

$info("\n--- SUMMARY ---");
printf("episode_id=%d\n", (int) $episode->id);
printf("selected_quality=%s\n", $selected);
printf("selected_score=%d\n", qualityScore($selected));
printf("upstream_host=%s\n", $meta['host']);
printf("range_support=OK\n");
printf("mp4_magic=OK\n");
printf("E2E_UPSTREAM_TERMINAL=OK\n");
