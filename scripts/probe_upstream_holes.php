<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/probe_upstream_holes.php
 * Fungsi : probe read-only untuk menemukan pintu upstream yang exhaustif setelah get_videos bolong
 * Rujuk  : Blueprint_ANIMEKU §3, §4, §6; ZONAOTAKU_V2_FINAL.md §5.3, §13.5
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = rtrim((string) config('zonaotaku.animeku.base_url_primary'), '/');
$key = (string) config('zonaotaku.animeku.api_key');
$headerMap = config('zonaotaku.animeku.headers', []);
$headers = [];

if (is_array($headerMap)) {
    foreach ($headerMap as $hk => $hv) {
        if (is_string($hk) && (is_string($hv) || is_numeric($hv))) {
            $headers[] = $hk . ': ' . (string) $hv;
        }
    }
}

$lastRequestAt = 0.0;

function throttle(): void
{
    global $lastRequestAt;

    $now = microtime(true);
    $wait = 1.2 - ($now - $lastRequestAt);

    if ($wait > 0) {
        usleep((int) ($wait * 1000000));
    }

    $lastRequestAt = microtime(true);
}

function redactUrl(string $url, string $key): string
{
    return $key !== '' ? str_replace($key, '<redacted>', $url) : $url;
}

function request(string $url, array $headers): array
{
    throttle();

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'http' => $http,
        'body' => is_string($body) ? $body : '',
        'err' => $err,
    ];
}

/**
 * @return array{0:string|null,1:array<int,mixed>}
 */
function firstList(array $json): array
{
    foreach ($json as $k => $v) {
        if (is_array($v) && $v !== [] && is_array(reset($v))) {
            return [(string) $k, $v];
        }
    }

    return [null, []];
}

/**
 * @param array<mixed> $item
 */
function itemTitle(array $item): string
{
    foreach (['video_title', 'title', 'name', 'genre_anime'] as $f) {
        if (isset($item[$f]) && is_string($item[$f]) && trim($item[$f]) !== '') {
            return trim($item[$f]);
        }
    }

    return '-';
}

/**
 * @param array<string,mixed> $r
 */
function summarize(string $label, string $url, array $r, string $key): void
{
    printf("\n== %s ==\n", $label);
    printf("URL: %s\n", redactUrl($url, $key));

    if ($r['err'] !== '') {
        printf("CURL_ERROR: %s\n", $r['err']);

        return;
    }

    printf("HTTP: %d\n", $r['http']);

    if ($r['body'] === '') {
        echo "EMPTY_BODY\n";

        return;
    }

    $json = json_decode($r['body'], true);

    if (!is_array($json)) {
        printf(
            "INVALID_JSON first240: %s\n",
            substr((string) preg_replace('/\s+/', ' ', $r['body']), 0, 240)
        );

        return;
    }

    $scalars = [];

    foreach ($json as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $scalars[$k] = $v;
        }
    }

    echo 'TOP_SCALARS: ' . json_encode($scalars, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    [$listKey, $list] = firstList($json);

    printf("LIST_KEY: %s COUNT: %d\n", $listKey ?? '-', count($list));

    if ($list !== []) {
        $first = $list[0] ?? null;
        $last = $list !== [] ? end($list) : null;

        printf("FIRST: %s\n", substr(itemTitle(is_array($first) ? $first : []), 0, 90));
        printf("LAST: %s\n", substr(itemTitle(is_array($last) ? $last : []), 0, 90));

        if (is_array($first)) {
            $keys = array_keys($first);
            echo 'ITEM_KEYS: ' . implode(',', array_slice($keys, 0, 35)) . "\n";
        }
    }
}

$probes = [];

$add = static function (string $label, string $endpoint, array $params) use (&$probes, $base, $key): void {
    $params['api_key'] = $key;

    $probes[] = [
        'label' => $label,
        'url' => $base . '/' . $endpoint . '?' . http_build_query($params),
    ];
};

foreach ([
    [766, 100],
    [767, 100],
    [768, 100],
    [769, 100],
    [770, 100],
    [768, 20],
    [768, 50],
    [911, 20],
] as [$page, $count]) {
    $add("get_videos p={$page} c={$count}", 'get_videos', [
        'page' => $page,
        'count' => $count,
    ]);
}

$catIds = [];

try {
    $popularCatIds = DB::table('anime')
        ->whereNotNull('cat_id')
        ->orderByDesc('view_count')
        ->limit(2)
        ->pluck('cat_id')
        ->all();

    $detectiveCatIds = DB::table('anime')
        ->where('title', 'like', 'Detective%')
        ->whereNotNull('cat_id')
        ->limit(1)
        ->pluck('cat_id')
        ->all();

    $catIds = array_merge($popularCatIds, $detectiveCatIds);
} catch (Throwable $e) {
    echo 'DB_CAT_WARN: ' . $e->getMessage() . "\n";
}

$catIds = array_values(array_unique(array_filter(
    array_map('intval', $catIds),
    static fn (int $v): bool => $v > 0
)));

foreach (array_slice($catIds, 0, 3) as $id) {
    $add("get_anime_detail cat_id={$id}", 'get_anime_detail', ['id' => $id]);
    $add("get_post_detail cat_id={$id}", 'get_post_detail', ['id' => $id]);
}

$videoIds = [];

try {
    $videoIds = DB::table('episodes')
        ->whereNotNull('external_id')
        ->where('external_id', '!=', '')
        ->limit(2)
        ->pluck('external_id')
        ->all();
} catch (Throwable $e) {
    echo 'DB_VIDEO_WARN: ' . $e->getMessage() . "\n";
}

foreach (array_slice($videoIds, 0, 2) as $vid) {
    $vid = (string) $vid;

    if ($vid === '') {
        continue;
    }

    $labelVid = strlen($vid) > 18 ? substr($vid, 0, 18) . '...' : $vid;

    $add("get_anime_detail video_id={$labelVid}", 'get_anime_detail', ['id' => $vid]);
    $add("get_post_detail video_id={$labelVid}", 'get_post_detail', ['id' => $vid]);
}

$add('get_category_popular p=1 c=20', 'get_category_popular', [
    'page' => 1,
    'count' => 20,
]);

$add('get_category_popular p=298 c=20', 'get_category_popular', [
    'page' => 298,
    'count' => 20,
]);

$genreIndexUrl = $base . '/get_genre_index?' . http_build_query([
    'count' => 100,
    'api_key' => $key,
]);

$genreIndex = request($genreIndexUrl, $headers);

echo "\n== get_genre_index ==\n";
printf("URL: %s\n", redactUrl($genreIndexUrl, $key));
printf("HTTP: %d\n", $genreIndex['http']);

$genrePairs = [];

if ($genreIndex['err'] !== '') {
    printf("CURL_ERROR: %s\n", $genreIndex['err']);
} elseif ($genreIndex['body'] === '') {
    echo "EMPTY_BODY\n";
} else {
    preg_match_all(
        '/\bid:\s*(\d+)\s*,\s*genre_anime:\s*"([^"]+)"/i',
        $genreIndex['body'],
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $m) {
        $genrePairs[] = [(int) $m[1], (string) $m[2]];
    }

    printf("GENRE_PARSED: %d\n", count($genrePairs));

    if ($genrePairs === []) {
        printf(
            "RAW_FIRST_240: %s\n",
            substr((string) preg_replace('/\s+/', ' ', $genreIndex['body']), 0, 240)
        );
    } else {
        foreach (array_slice($genrePairs, 0, 8) as [$gid, $gname]) {
            printf(" - %d %s\n", $gid, $gname);
        }
    }
}

foreach (array_slice($genrePairs, 0, 3) as [$gid, $gname]) {
    $add("get_category_genre id={$gid}", 'get_category_genre', [
        'search' => $gid,
        'page' => 1,
        'count' => 20,
    ]);

    $add("get_category_genre name={$gname}", 'get_category_genre', [
        'search' => $gname,
        'page' => 1,
        'count' => 20,
    ]);
}

foreach ($probes as $probe) {
    $res = request($probe['url'], $headers);
    summarize($probe['label'], $probe['url'], $res, $key);
}

echo "\nPROBE_DONE\n";
