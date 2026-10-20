<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/verify_episode_order.php
 * Fungsi : verify urutan episode One Piece dan duplikat global anime_id+number
 * Rujuk  : Blueprint_ANIMEKU.md §4; ZONAOTAKU_V2_FINAL.md §13.5
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$anime = DB::table('anime')
    ->where('slug', 'one-piece')
    ->first(['id', 'title', 'cat_id', 'episodes_total']);

if ($anime === null) {
    fwrite(STDERR, "FAIL: anime one-piece tidak ditemukan\n");
    exit(1);
}

printf("ONE PIECE anime_id=%d cat_id=%s title=%s episodes_total=%d\n",
    (int) $anime->id,
    (string) ($anime->cat_id ?? '-'),
    (string) $anime->title,
    (int) ($anime->episodes_total ?? 0)
);

$rows = DB::table('episodes')
    ->where('anime_id', $anime->id)
    ->orderBy('number')
    ->orderBy('id')
    ->get(['id', 'number', 'title']);

printf("ONE PIECE episodes_db=%d\n", count($rows));

echo "\n--- FIRST 12 ---\n";

foreach ($rows->take(12) as $row) {
    printf(
        "%d | number=%s | %s\n",
        (int) $row->id,
        number_format((float) $row->number, 2, ',', '.'),
        mb_substr((string) $row->title, 0, 70)
    );
}

echo "\n--- LAST 12 ---\n";

foreach ($rows->reverse()->take(12)->reverse() as $row) {
    printf(
        "%d | number=%s | %s\n",
        (int) $row->id,
        number_format((float) $row->number, 2, ',', '.'),
        mb_substr((string) $row->title, 0, 70)
    );
}

$bad = $rows->filter(static function (object $row): bool {
    $title = (string) $row->title;

    if (preg_match('/\b(?:eps?|episode|ep)\.?\s*#?\s*(\d+(?:[.,]\d+)?)\b/iu', $title, $m) !== 1) {
        return false;
    }

    $parsed = round((float) str_replace(',', '.', $m[1]), 2);
    $stored = round((float) $row->number, 2);
    $base = floor($stored);
    $frac = (int) round(($stored - $base) * 100);

    if ($frac > 0) {
        return abs($base - $parsed) > 0.001;
    }

    return abs($stored - $parsed) > 0.001;
})->values();

printf("\nONE PIECE title_number_mismatch=%d\n", count($bad));

foreach ($bad->take(10) as $row) {
    printf(
        "MISMATCH %d | number=%s | %s\n",
        (int) $row->id,
        number_format((float) $row->number, 2, ',', '.'),
        mb_substr((string) $row->title, 0, 70)
    );
}

$duplicates = DB::select('
    SELECT anime_id, number, COUNT(*) AS c
    FROM episodes
    GROUP BY anime_id, number
    HAVING c > 1
    ORDER BY c DESC
    LIMIT 20
');

printf("\nGLOBAL duplicate_anime_number_pairs=%d\n", count($duplicates));

foreach ($duplicates as $row) {
    printf(
        "DUP anime_id=%d number=%s count=%d\n",
        (int) $row->anime_id,
        number_format((float) $row->number, 2, ',', '.'),
        (int) $row->c
    );
}

$index = DB::select("SHOW INDEX FROM `episodes` WHERE Key_name = 'uq_ep'");

printf("\nUQ_EP_INDEX=%s\n", $index === [] ? 'MISSING' : 'OK');

if ($duplicates === [] && $index !== []) {
    echo "VERIFY=OK\n";
    exit(0);
}

echo "VERIFY=CHECK_ABOVE\n";
