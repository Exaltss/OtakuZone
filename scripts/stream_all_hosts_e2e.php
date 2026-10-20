<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/stream_all_hosts_e2e.php
 * Fungsi : probe SEMUA host stream dari episodes.quality_urls tanpa menghapus asset non-whatbox
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.4, §2.5, §3.1, §4.3, §5.4; Blueprint_ANIMEKU.md §4, §5
 *
 * Prinsip:
 * - Semua URL http/https valid dan publik adalah asset.
 * - whatbox.ca hanya label, bukan filter penghapus.
 * - DB tidak diubah.
 * - Terminal/report tidak mencetak URL penuh, hanya host + path hash.
 */
declare(strict_types=1);

use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$options = getopt('', [
    'probe-bytes::',
    'limit-hosts::',
    'report::',
]);

$probeBytes = max(65536, min(1048576, (int) ($options['probe-bytes'] ?? 131072)));
$limitHosts = max(0, (int) ($options['limit-hosts'] ?? 0));
$reportPath = trim((string) ($options['report'] ?? 'storage/app/sync/all-host-stream-probe.json'));

if ($reportPath === '') {
    $reportPath = 'storage/app/sync/all-host-stream-probe.json';
}

$reportFullPath = base_path($reportPath);
@mkdir(dirname($reportFullPath), 0775, true);

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

function isPublicIp(string $ip): bool
{
    $ip = trim($ip);

    if ($ip === '') {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    return false;
}

/**
 * @return array{ok:bool,reason:string,ips:array<int,string>}
 */
function hostIsAllowed(string $host): array
{
    $host = strtolower(trim($host));

    if ($host === '') {
        return ['ok' => false, 'reason' => 'empty_host', 'ips' => []];
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (isPublicIp($host)) {
            return ['ok' => true, 'reason' => 'ok', 'ips' => [$host]];
        }

        return ['ok' => false, 'reason' => 'private_or_reserved_ip', 'ips' => [$host]];
    }

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);

    if ($records === false || $records === []) {
        return ['ok' => false, 'reason' => 'dns_unresolved', 'ips' => []];
    }

    $ips = [];

    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

        if ($ip === '') {
            continue;
        }

        if (!isPublicIp($ip)) {
            return ['ok' => false, 'reason' => 'resolved_private_or_reserved_ip', 'ips' => [$ip]];
        }

        $ips[] = $ip;
    }

    if ($ips === []) {
        return ['ok' => false, 'reason' => 'dns_no_ip', 'ips' => []];
    }

    return ['ok' => true, 'reason' => 'ok', 'ips' => $ips];
}

/**
 * @return array{ok:bool,host:string,scheme:string,path:string,path_hash:string,reason:string}
 */
function validateUrl(string $url): array
{
    $out = [
        'ok' => false,
        'host' => '',
        'scheme' => '',
        'path' => '',
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
    $out['path'] = $path;
    $out['path_hash'] = substr(hash('sha256', $path), 0, 16);

    if (!in_array($scheme, ['http', 'https'], true)) {
        $out['reason'] = 'bad_scheme';
        return $out;
    }

    if ($host === '') {
        $out['reason'] = 'bad_host';
        return $out;
    }

    $allowed = hostIsAllowed($host);

    if (!$allowed['ok']) {
        $out['reason'] = $allowed['reason'];
        return $out;
    }

    $out['ok'] = true;
    $out['reason'] = 'ok';

    return $out;
}

function isWhatbox(string $host): bool
{
    $host = strtolower(trim($host));

    return $host === 'whatbox.ca' || str_ends_with($host, '.whatbox.ca');
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

function safeSnippet(string $body, int $limit = 128): string
{
    $head = substr($body, 0, $limit);
    $printable = preg_replace('/[^\x20-\x7E]/', '.', $head) ?? '';
    $redacted = preg_replace('#(?:https?:)?//[^\s<>"\'`]+#i', '[URL]', $printable) ?? $printable;

    return $redacted . ($limit < strlen($body) ? '...' : '');
}

function hexHead(string $body, int $limit = 24): string
{
    return strtoupper(bin2hex(substr($body, 0, $limit)));
}

function magicLabel(array $r): string
{
    $head = substr((string) ($r['body'] ?? ''), 0, 64);

    if (str_contains($head, 'ftyp')) {
        return 'ftyp';
    }

    if (str_contains($head, 'moov')) {
        return 'moov';
    }

    $ct = strtolower((string) ($r['content_type'] ?? ''));

    if (str_contains($ct, 'video/') || str_contains($ct, 'octet-stream')) {
        return 'content-type';
    }

    return '-';
}

function looksHtmlError(array $r): bool
{
    $snip = strtolower(safeSnippet((string) ($r['body'] ?? ''), 256));

    return str_contains($snip, '<html')
        || str_contains($snip, '<!doctype')
        || str_contains($snip, 'not found')
        || str_contains($snip, 'forbidden')
        || str_contains($snip, 'unauthorized')
        || str_contains($snip, 'bad request')
        || str_contains($snip, 'error');
}

/**
 * @return array<string,array<int,string>>
 */
function headerProfiles(string $host): array
{
    $dalvik = 'Dalvik/7.1.12.1.0 (com.newanimeku.animechanneldonghuasubindosubenglish U; Android ; 20175 Build/NMF260)';
    $desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    return [
        'animeku' => [
            "User-Agent: {$dalvik}",
            'Data-Agent: Your Videos Channel',
            'Accept: */*',
            'Referer: https://pencarinafkah.xyz/',
            'Origin: ',
        ],
        'no_referer' => [
            "User-Agent: {$dalvik}",
            'Data-Agent: Your Videos Channel',
            'Accept: */*',
        ],
        'host_referer' => [
            "User-Agent: {$desktop}",
            'Accept: */*',
            "Referer: https://{$host}/",
            "Origin: https://{$host}",
        ],
        'plain_desktop' => [
            "User-Agent: {$desktop}",
            'Accept: video/*, application/octet-stream, */*',
        ],
    ];
}

/**
 * @param array<int,string> $headers
 * @return array<string,mixed>
 */
function fetchRange(string $url, string $range, int $maxBytes, array $headers): array
{
    if (!function_exists('curl_init')) {
        throw new \RuntimeException('Ekstensi PHP curl tidak tersedia.');
    }

    $body = '';
    $respHeaders = [];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 30,
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
    $redirectCount = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);

    curl_close($ch);

    if ($errno === 23 && strlen($body) >= $maxBytes) {
        $errno = 0;
        $error = '';
    }

    $effective = validateUrl($effectiveUrl);

    return [
        'errno' => $errno,
        'error' => $error,
        'status' => $status,
        'content_type' => $contentType !== '' ? $contentType : (string) ($respHeaders['content-type'] ?? ''),
        'headers' => $respHeaders,
        'body' => $body,
        'length' => strlen($body),
        'effective_url_ok' => (bool) $effective['ok'],
        'effective_host' => (string) ($effective['host'] ?: '-'),
        'effective_path_hash' => (string) ($effective['path_hash'] ?: '-'),
        'effective_reason' => (string) ($effective['reason'] ?: '-'),
        'redirect_count' => $redirectCount,
        'snippet' => safeSnippet($body),
        'hex_head' => hexHead($body),
    ];
}

/**
 * @param array<string,mixed> $sample
 * @return array<string,mixed>
 */
function probeWithProfiles(array $sample, int $bytes): array
{
    $profiles = headerProfiles((string) $sample['host']);
    $half = intdiv($bytes, 2);
    $secondLen = $bytes - $half;

    $r1Range = '0-' . (string) ($half - 1);
    $r2Range = $half . '-' . (string) ($bytes - 1);

    $best = null;
    $bestRank = -1;

    foreach ($profiles as $profileName => $headers) {
        try {
            $r1 = fetchRange((string) $sample['url'], $r1Range, $half, $headers);
            $r2 = fetchRange((string) $sample['url'], $r2Range, $secondLen, $headers);
        } catch (\Throwable $e) {
            $candidate = [
                'profile' => (string) $profileName,
                'playable' => false,
                'note' => 'exception:' . $e->getMessage(),
                'status1' => 0,
                'status2' => 0,
                'len1' => 0,
                'len2' => 0,
                'range_ok' => false,
                'content_type' => '-',
                'magic1' => '-',
                'magic2' => '-',
                'effective_host1' => '-',
                'effective_host2' => '-',
                'redirect_count' => 0,
                'snippet1' => '',
                'snippet2' => '',
            ];

            if ($best === null || 0 > $bestRank) {
                $best = $candidate;
                $bestRank = 0;
            }

            continue;
        }

        $statusOk1 = in_array((int) $r1['status'], [200, 206], true);
        $statusOk2 = in_array((int) $r2['status'], [200, 206], true);
        $lenOk1 = (int) $r1['length'] >= intdiv($half, 2);
        $lenOk2 = (int) $r2['length'] >= intdiv($secondLen, 2);
        $rangeOk = (int) $r1['status'] === 206 && (int) $r2['status'] === 206;

        $magic1 = magicLabel($r1);
        $magic2 = magicLabel($r2);

        $ct = strtolower((string) ($r1['content_type'] ?: $r2['content_type'] ?: ''));
        $looksMedia = str_contains($ct, 'video/') || str_contains($ct, 'octet-stream');

        $htmlErr = looksHtmlError($r1) || looksHtmlError($r2);
        $mediaOk = !$htmlErr && ($magic1 !== '-' || $magic2 !== '-' || $looksMedia);

        $effOk = (bool) $r1['effective_url_ok'] && (bool) $r2['effective_url_ok'];

        $playable = $statusOk1 && $statusOk2 && $lenOk1 && $lenOk2 && $mediaOk && $effOk;

        $note = 'ok';

        if (!$effOk) {
            $note = 'redirect_or_effective_blocked';
        } elseif (!$statusOk1 || !$statusOk2) {
            $note = 'http_status';
        } elseif (!$lenOk1 || !$lenOk2) {
            $note = 'short_body';
        } elseif ($htmlErr) {
            $note = 'html_or_error_page';
        } elseif (!$rangeOk) {
            $note = 'range_not_206';
        }

        $rank = 0;

        if ($playable) {
            $rank += 1000;
        }

        if ($effOk) {
            $rank += 120;
        }

        if ($mediaOk) {
            $rank += 90;
        }

        if ($rangeOk) {
            $rank += 70;
        }

        if ($statusOk1 && $statusOk2) {
            $rank += 50;
        }

        if ($lenOk1 && $lenOk2) {
            $rank += 40;
        }

        $rank += min(30, (int) (((int) $r1['length'] + (int) $r2['length']) / 8192));

        $candidate = [
            'profile' => (string) $profileName,
            'playable' => $playable,
            'note' => $note,
            'status1' => (int) $r1['status'],
            'status2' => (int) $r2['status'],
            'len1' => (int) $r1['length'],
            'len2' => (int) $r2['length'],
            'range_ok' => $rangeOk,
            'content_type' => (string) ($r1['content_type'] ?: $r2['content_type'] ?: '-'),
            'magic1' => $magic1,
            'magic2' => $magic2,
            'effective_host1' => (string) $r1['effective_host'],
            'effective_host2' => (string) $r2['effective_host'],
            'effective_reason1' => (string) $r1['effective_reason'],
            'effective_reason2' => (string) $r2['effective_reason'],
            'redirect_count' => max((int) $r1['redirect_count'], (int) $r2['redirect_count']),
            'curl_errno1' => (int) $r1['errno'],
            'curl_errno2' => (int) $r2['errno'],
            'curl_error1' => (string) $r1['error'],
            'curl_error2' => (string) $r2['error'],
            'hex_head1' => (string) $r1['hex_head'],
            'hex_head2' => (string) $r2['hex_head'],
            'snippet1' => (string) $r1['snippet'],
            'snippet2' => (string) $r2['snippet'],
        ];

        if ($best === null || $rank > $bestRank) {
            $best = $candidate;
            $bestRank = $rank;
        }

        if ($playable) {
            break;
        }
    }

    if ($best === null) {
        return [
            'profile' => '-',
            'playable' => false,
            'note' => 'no_profile_result',
            'status1' => 0,
            'status2' => 0,
            'len1' => 0,
            'len2' => 0,
            'range_ok' => false,
            'content_type' => '-',
            'magic1' => '-',
            'magic2' => '-',
            'effective_host1' => '-',
            'effective_host2' => '-',
            'redirect_count' => 0,
            'snippet1' => '',
            'snippet2' => '',
        ];
    }

    return $best;
}

$stats = [
    'episodes_scanned' => 0,
    'raw_total' => 0,
    'raw_looks_encrypted' => 0,
    'episodes_empty_quality' => 0,
    'episodes_valid_any' => 0,
    'episodes_invalid_all' => 0,
    'valid_urls_total' => 0,
    'invalid_nonstring_empty_urls' => 0,
    'invalid_url_format' => 0,
    'blocked_private_or_dns' => 0,
];

$hosts = [];
$hostSamples = [];
$animeCache = [];

Episode::query()
    ->orderBy('id')
    ->chunkById(500, function ($episodes) use (
        &$stats,
        &$hosts,
        &$hostSamples,
        &$animeCache
    ): void {
        foreach ($episodes as $ep) {
            $stats['episodes_scanned']++;

            $raw = $ep->getRawOriginal('quality_urls');
            $stats['raw_total']++;

            if (is_string($raw) && $raw !== '') {
                $looksEncrypted = str_starts_with($raw, 'eyJpdiI6') || json_decode($raw, true) === null;

                if ($looksEncrypted) {
                    $stats['raw_looks_encrypted']++;
                }
            }

            $qualities = decodeQuality($ep);

            if ($qualities === []) {
                $stats['episodes_empty_quality']++;

                continue;
            }

            $epValid = false;
            $epHosts = [];

            foreach ($qualities as $key => $value) {
                $key = (string) $key;

                if (!is_string($value) || $value === '') {
                    $stats['invalid_nonstring_empty_urls']++;

                    continue;
                }

                $v = validateUrl($value);

                if (!$v['ok']) {
                    if (in_array($v['reason'], [
                        'private_or_reserved_ip',
                        'resolved_private_or_reserved_ip',
                        'dns_unresolved',
                        'dns_no_ip',
                        'empty_host',
                    ], true)) {
                        $stats['blocked_private_or_dns']++;
                    } else {
                        $stats['invalid_url_format']++;
                    }

                    continue;
                }

                $host = (string) $v['host'];
                $score = qualityScore($key);
                $number = (float) $ep->number;

                if (!isset($hosts[$host])) {
                    $hosts[$host] = [
                        'host' => $host,
                        'whatbox' => isWhatbox($host),
                        'urls' => 0,
                        'episodes' => 0,
                        'keys' => [],
                    ];
                }

                $hosts[$host]['urls']++;
                $hosts[$host]['keys'][$key] = ($hosts[$host]['keys'][$key] ?? 0) + 1;

                $stats['valid_urls_total']++;
                $epValid = true;
                $epHosts[$host] = true;

                $current = $hostSamples[$host] ?? null;

                $better = false;

                if ($current === null) {
                    $better = true;
                } elseif ($score > (int) $current['score']) {
                    $better = true;
                } elseif ($score === (int) $current['score'] && $number > (float) $current['number']) {
                    $better = true;
                } elseif (
                    $score === (int) $current['score']
                    && $number === (float) $current['number']
                    && (int) $ep->id < (int) $current['episode_id']
                ) {
                    $better = true;
                }

                if ($better) {
                    $animeId = (int) $ep->anime_id;

                    if (!array_key_exists($animeId, $animeCache)) {
                        $animeCache[$animeId] = Anime::query()->find($animeId);
                    }

                    $anime = $animeCache[$animeId];

                    $hostSamples[$host] = [
                        'host' => $host,
                        'whatbox' => isWhatbox($host),
                        'episode_id' => (int) $ep->id,
                        'anime_id' => $animeId,
                        'anime_title' => $anime === null ? '-' : (string) $anime->title,
                        'anime_slug' => $anime === null ? '-' : (string) $anime->slug,
                        'cat_id' => $anime === null ? null : (int) ($anime->cat_id ?? 0),
                        'number' => $number,
                        'episode_title' => (string) ($ep->title ?? '-'),
                        'quality_key' => $key,
                        'score' => $score,
                        'path_hash' => (string) $v['path_hash'],
                        'url' => $value,
                    ];
                }
            }

            foreach ($epHosts as $h => $_) {
                $hosts[$h]['episodes']++;
            }

            if ($epValid) {
                $stats['episodes_valid_any']++;
            } else {
                $stats['episodes_invalid_all']++;
            }
        }
    });

$hostList = array_values($hosts);

usort($hostList, static function (array $a, array $b): int {
    if ((int) $b['urls'] !== (int) $a['urls']) {
        return (int) $b['urls'] <=> (int) $a['urls'];
    }

    if ((int) $b['episodes'] !== (int) $a['episodes']) {
        return (int) $b['episodes'] <=> (int) $a['episodes'];
    }

    return strcmp((string) $a['host'], (string) $b['host']);
});

$whatboxHosts = 0;
$nonwhatboxHosts = 0;

foreach ($hostList as $h) {
    if ((bool) $h['whatbox']) {
        $whatboxHosts++;
    } else {
        $nonwhatboxHosts++;
    }
}

$encPct = $stats['raw_total'] > 0
    ? round(($stats['raw_looks_encrypted'] / $stats['raw_total']) * 100, 2)
    : 0.0;

echo "--- ALL HOST STREAM SCAN ---\n";
printf("episodes_scanned=%d\n", (int) $stats['episodes_scanned']);
printf("raw_total=%d\n", (int) $stats['raw_total']);
printf("raw_looks_encrypted=%d (%.2f%%)\n", (int) $stats['raw_looks_encrypted'], $encPct);
printf("episodes_empty_quality=%d\n", (int) $stats['episodes_empty_quality']);
printf("episodes_valid_any=%d\n", (int) $stats['episodes_valid_any']);
printf("episodes_invalid_all=%d\n", (int) $stats['episodes_invalid_all']);
printf("valid_urls_total=%d\n", (int) $stats['valid_urls_total']);
printf("invalid_nonstring_empty_urls=%d\n", (int) $stats['invalid_nonstring_empty_urls']);
printf("invalid_url_format=%d\n", (int) $stats['invalid_url_format']);
printf("blocked_private_or_dns=%d\n", (int) $stats['blocked_private_or_dns']);
printf("distinct_hosts=%d\n", count($hostList));
printf("whatbox_hosts=%d\n", $whatboxHosts);
printf("nonwhatbox_hosts=%d\n", $nonwhatboxHosts);

echo "\n--- HOST INVENTORY ---\n";

foreach ($hostList as $h) {
    $keys = $h['keys'];
    arsort($keys);
    $top = [];

    foreach (array_slice($keys, 0, 8, true) as $key => $count) {
        $top[] = $key . ':' . (string) $count;
    }

    printf(
        "host=%s | whatbox=%s | episodes=%d | urls=%d | keys=%s\n",
        (string) $h['host'],
        (bool) $h['whatbox'] ? 'yes' : 'no',
        (int) $h['episodes'],
        (int) $h['urls'],
        $top === [] ? '-' : implode(',', $top)
    );
}

$probeHosts = $limitHosts > 0
    ? array_slice($hostList, 0, $limitHosts)
    : $hostList;

echo "\n--- ALL HOST RANGE PROBE ---\n";
printf("probe_bytes=%d\n", $probeBytes);
printf("probe_hosts=%d\n", count($probeHosts));

$results = [];
$playableHosts = 0;
$failedHosts = 0;

foreach ($probeHosts as $h) {
    $host = (string) $h['host'];
    $sample = $hostSamples[$host] ?? null;

    if ($sample === null) {
        printf("host=%s result=NO_SAMPLE\n", $host);
        $failedHosts++;

        continue;
    }

    $p = probeWithProfiles($sample, $probeBytes);
    $results[$host] = $p;

    if ((bool) $p['playable']) {
        $playableHosts++;
    } else {
        $failedHosts++;
    }

    printf(
        "host=%s | whatbox=%s | ep=%d | anime=%s | number=%s | quality=%s | profile=%s | status=%d/%d | range_ok=%s | playable=%s | len=%d/%d | ct=%s | magic=%s/%s | eff=%s/%s | redirects=%d | note=%s\n",
        $host,
        (bool) $sample['whatbox'] ? 'yes' : 'no',
        (int) $sample['episode_id'],
        mb_substr((string) $sample['anime_title'], 0, 38),
        number_format((float) $sample['number'], 2, ',', '.'),
        (string) $sample['quality_key'],
        (string) $p['profile'],
        (int) $p['status1'],
        (int) $p['status2'],
        (bool) $p['range_ok'] ? 'yes' : 'no',
        (bool) $p['playable'] ? 'yes' : 'no',
        (int) $p['len1'],
        (int) $p['len2'],
        (string) $p['content_type'],
        (string) $p['magic1'],
        (string) $p['magic2'],
        (string) $p['effective_host1'],
        (string) $p['effective_host2'],
        (int) $p['redirect_count'],
        (string) $p['note']
    );

    if (!(bool) $p['playable']) {
        printf("  curl_err=%d/%d %s%s\n",
            (int) ($p['curl_errno1'] ?? 0),
            (int) ($p['curl_errno2'] ?? 0),
            (string) ($p['curl_error1'] ?? ''),
            (string) ($p['curl_error2'] ?? '') !== '' ? ' | ' . (string) $p['curl_error2'] : ''
        );
        printf("  snippet1=%s\n", (string) $p['snippet1']);
        printf("  snippet2=%s\n", (string) $p['snippet2']);
    }
}

echo "\n--- PROBE SUMMARY ---\n";
printf("distinct_hosts=%d\n", count($hostList));
printf("probed_hosts=%d\n", count($probeHosts));
printf("playable_hosts=%d\n", $playableHosts);
printf("failed_hosts=%d\n", $failedHosts);
printf("nonwhatbox_asset_policy=KEEP_ENCRYPTED_DO_NOT_DELETE\n");

$sanitizedSamples = [];

foreach ($hostSamples as $host => $sample) {
    unset($sample['url']);
    $sanitizedSamples[$host] = $sample;
}

$report = [
    'generated_at' => now()->toIso8601String(),
    'policy' => 'all_valid_public_http_https_urls_are_assets_keep_encrypted_do_not_delete',
    'stats' => $stats,
    'hosts' => array_map(static function (array $h): array {
        $keys = $h['keys'];
        arsort($keys);

        return [
            'host' => (string) $h['host'],
            'whatbox' => (bool) $h['whatbox'],
            'episodes' => (int) $h['episodes'],
            'urls' => (int) $h['urls'],
            'top_keys' => array_slice($keys, 0, 12, true),
        ];
    }, $hostList),
    'samples' => $sanitizedSamples,
    'probe_results' => $results,
    'summary' => [
        'probed_hosts' => count($probeHosts),
        'playable_hosts' => $playableHosts,
        'failed_hosts' => $failedHosts,
    ],
];

file_put_contents(
    $reportFullPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

printf("\nREPORT=%s\n", $reportPath);
echo "ALL_HOST_PROBE_DONE=OK\n";
