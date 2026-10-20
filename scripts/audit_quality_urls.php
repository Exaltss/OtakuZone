<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/audit_quality_urls.php
 * Fungsi : mengaudit isi episodes.quality_urls tanpa membocorkan URL upstream
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.4, §2.5, §3.1, §4.3, §5.4
 */
declare(strict_types=1);

use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['global', 'slug::', 'limit::', 'sample-invalid::']);

$doGlobal = isset($options['global']);
$slug = trim((string) ($options['slug'] ?? ''));
$limit = max(1, (int) ($options['limit'] ?? 25));
$sampleInvalid = max(1, (int) ($options['sample-invalid'] ?? 30));

$fail = function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
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

function isStandardKey(string $key): bool
{
    return qualityScore($key) >= 60;
}

if ($doGlobal) {
    $agg = [
        'total' => 0,
        'empty' => 0,
        'episodes_valid_any' => 0,
        'episodes_has_standard' => 0,
        'episodes_only_nonstandard' => 0,
        'episodes_only_eng_hdeng' => 0,
        'episodes_invalid_all' => 0,
        'valid_urls' => 0,
        'invalid_nonstring_empty' => 0,
        'invalid_url' => 0,
        'nonwhatbox' => 0,
        'key_counts' => [],
    ];

    $hostCounts = [];
    $invalidSamples = [];
    $animeCache = [];

    $addInvalid = function (Episode $ep, string $reason, array $q) use (&$invalidSamples, $sampleInvalid, &$animeCache): void {
        if (count($invalidSamples) >= $sampleInvalid) {
            return;
        }

        if (!array_key_exists((int) $ep->anime_id, $animeCache)) {
            $animeCache[(int) $ep->anime_id] = Anime::find($ep->anime_id);
        }

        $anime = $animeCache[(int) $ep->anime_id];
        $keys = [];

        foreach (array_keys($q) as $k) {
            $keys[] = (string) $k;
        }

        $invalidSamples[] = [
            'episode_id' => (int) $ep->id,
            'anime_id' => (int) $ep->anime_id,
            'slug' => $anime === null ? '-' : (string) $anime->slug,
            'title' => $anime === null ? '-' : (string) $anime->title,
            'number' => number_format((float) $ep->number, 2, ',', '.'),
            'ep_title' => mb_substr((string) ($ep->title ?? '-'), 0, 55),
            'keys' => implode(',', array_slice($keys, 0, 8)),
            'reason' => $reason,
        ];
    };

    Episode::query()
        ->orderBy('id')
        ->chunkById(500, function ($episodes) use (&$agg, &$hostCounts, &$invalidSamples, $addInvalid): void {
            foreach ($episodes as $ep) {
                $agg['total']++;
                $q = decodeQuality($ep);

                if ($q === []) {
                    $agg['empty']++;
                    $addInvalid($ep, 'empty_quality_urls', $q);

                    continue;
                }

                $validKeys = [];
                $hasStandard = false;
                $reasons = [];

                foreach ($q as $k => $v) {
                    $key = (string) $k;

                    if (!is_string($v) || $v === '') {
                        $agg['invalid_nonstring_empty']++;
                        $reasons[] = $key . ':' . gettype($v);

                        continue;
                    }

                    $val = validateUrl($v);

                    if (!$val['ok']) {
                        $agg['invalid_url']++;
                        $reasons[] = $key . ':' . $val['reason'];

                        continue;
                    }

                    if (!$val['whatbox']) {
                        $agg['nonwhatbox']++;
                        $host = $val['host'] !== '' ? $val['host'] : '-';
                        $hostCounts[$host] = ($hostCounts[$host] ?? 0) + 1;
                        $reasons[] = $key . ':nonwhatbox:' . $host;

                        continue;
                    }

                    $validKeys[$key] = true;
                    $agg['valid_urls']++;
                    $agg['key_counts'][$key] = ($agg['key_counts'][$key] ?? 0) + 1;

                    if (isStandardKey($key)) {
                        $hasStandard = true;
                    }
                }

                if ($validKeys !== []) {
                    $agg['episodes_valid_any']++;

                    if ($hasStandard) {
                        $agg['episodes_has_standard']++;
                    } else {
                        $agg['episodes_only_nonstandard']++;
                    }

                    $onlyEngHdeng = true;

                    foreach (array_keys($validKeys) as $key) {
                        if (!in_array($key, ['eng', 'hdeng'], true)) {
                            $onlyEngHdeng = false;
                            break;
                        }
                    }

                    if (!$hasStandard && $onlyEngHdeng) {
                        $agg['episodes_only_eng_hdeng']++;
                    }
                } else {
                    $agg['episodes_invalid_all']++;
                    $addInvalid($ep, $reasons[0] ?? 'no_valid_whatbox', $q);
                }
            }
        });

    echo "--- GLOBAL QUALITY AUDIT ---\n";
    printf("episodes_total=%d\n", $agg['total']);
    printf("episodes_empty_quality=%d\n", $agg['empty']);
    printf("episodes_valid_any=%d\n", $agg['episodes_valid_any']);
    printf("episodes_has_standard=%d\n", $agg['episodes_has_standard']);
    printf("episodes_only_nonstandard=%d\n", $agg['episodes_only_nonstandard']);
    printf("episodes_only_eng_hdeng=%d\n", $agg['episodes_only_eng_hdeng']);
    printf("episodes_invalid_all=%d\n", $agg['episodes_invalid_all']);
    printf("valid_urls_total=%d\n", $agg['valid_urls']);
    printf("invalid_nonstring_empty_urls=%d\n", $agg['invalid_nonstring_empty']);
    printf("invalid_url_format=%d\n", $agg['invalid_url']);
    printf("nonwhatbox_urls=%d\n", $agg['nonwhatbox']);

    echo "\n--- VALID KEY COUNTS ---\n";
    arsort($agg['key_counts']);

    foreach (array_slice($agg['key_counts'], 0, 30, true) as $key => $count) {
        printf("key=%s count=%d\n", (string) $key, (int) $count);
    }

    if ($hostCounts !== []) {
        echo "\n--- NONWHATBOX HOST COUNTS ---\n";
        arsort($hostCounts);

        foreach (array_slice($hostCounts, 0, 30, true) as $host => $count) {
            printf("host=%s count=%d\n", (string) $host, (int) $count);
        }
    }

    if ($invalidSamples !== []) {
        echo "\n--- INVALID SAMPLES ---\n";

        foreach ($invalidSamples as $row) {
            printf(
                "ep=%d | anime=%d | slug=%s | number=%s | %s | keys=%s | reason=%s\n",
                (int) $row['episode_id'],
                (int) $row['anime_id'],
                (string) $row['slug'],
                (string) $row['number'],
                (string) $row['ep_title'],
                (string) $row['keys'],
                (string) $row['reason']
            );
        }
    }
}

if ($slug !== '') {
    $anime = Anime::query()->where('slug', $slug)->first();

    if ($anime === null) {
        $fail("Anime tidak ditemukan: slug={$slug}");
    }

    printf("\n--- SLUG AUDIT: %s ---\n", $slug);
    printf(
        "anime_id=%d | cat_id=%s | title=%s | episodes_total=%d\n",
        (int) $anime->id,
        (string) ($anime->cat_id ?? '-'),
        mb_substr((string) $anime->title, 0, 70),
        (int) ($anime->episodes_total ?? 0)
    );

    $eps = Episode::query()
        ->where('anime_id', $anime->id)
        ->orderByDesc('number')
        ->limit($limit)
        ->get();

    foreach ($eps as $ep) {
        $q = decodeQuality($ep);
        $keys = [];
        $valid = [];
        $hosts = [];
        $reasons = [];

        foreach ($q as $k => $v) {
            $key = (string) $k;
            $keys[] = $key;

            if (!is_string($v) || $v === '') {
                $reasons[] = $key . ':' . gettype($v);
                continue;
            }

            $val = validateUrl($v);

            if (!$val['ok']) {
                $reasons[] = $key . ':' . $val['reason'];
                continue;
            }

            if (!$val['whatbox']) {
                $reasons[] = $key . ':nonwhatbox:' . ($val['host'] ?: '-');
                continue;
            }

            $valid[] = $key;
            $hosts[] = $val['host'];
        }

        printf(
            "ep_id=%d | number=%s | title=%s | keys=%s | valid=%s | hosts=%s | reasons=%s\n",
            (int) $ep->id,
            number_format((float) $ep->number, 2, ',', '.'),
            mb_substr((string) ($ep->title ?? '-'), 0, 55),
            $keys === [] ? '-' : implode(',', array_slice($keys, 0, 10)),
            $valid === [] ? '-' : implode(',', array_slice($valid, 0, 10)),
            $hosts === [] ? '-' : implode(',', array_unique(array_slice($hosts, 0, 10))),
            $reasons === [] ? '-' : implode(' | ', array_slice($reasons, 0, 5))
        );
    }
}

if (!$doGlobal && $slug === '') {
    $fail('Gunakan --global dan/atau --slug=<slug>.');
}

echo "\nAUDIT_QUALITY_DONE=OK\n";
