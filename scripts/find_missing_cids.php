<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/find_missing_cids.php
 * Fungsi : mencari cid populer yang masih zero episode + cid error/empty dari log
 * Rujuk  : Blueprint_ANIMEKU.md §3.2, §3.3, §4; ZONAOTAKU_V2_FINAL.md §5.4
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cidFile = base_path('storage/app/sync/detail-cids.json');
$logFile = base_path('storage/logs/sync-detail.out');
$outFile = base_path('storage/app/sync/missing-cids.txt');

$cids = [];

if (is_file($cidFile)) {
    $json = json_decode((string) file_get_contents($cidFile), true);

    if (is_array($json)) {
        foreach ($json as $cid) {
            $cid = (int) $cid;

            if ($cid > 0) {
                $cids[$cid] = true;
            }
        }
    }
}

$problem = [];

if (is_file($logFile)) {
    $log = (string) file_get_contents($logFile);

    if (preg_match_all('/\bcid=(\d+)\s+empty_detail\b/', $log, $m) === 1) {
        foreach ($m[1] as $cid) {
            $cid = (int) $cid;

            if ($cid > 0) {
                $problem[$cid] = true;
            }
        }
    }

    if (preg_match_all('/\bdetail_failed_cid_(\d+)\b/', $log, $m) === 1) {
        foreach ($m[1] as $cid) {
            $cid = (int) $cid;

            if ($cid > 0) {
                $problem[$cid] = true;
            }
        }
    }
}

$counts = [];

$rows = DB::table('anime')
    ->leftJoin('episodes', 'episodes.anime_id', '=', 'anime.id')
    ->whereNotNull('anime.cat_id')
    ->selectRaw('anime.cat_id AS cat_id, COUNT(episodes.id) AS episode_count')
    ->groupBy('anime.cat_id')
    ->get();

foreach ($rows as $row) {
    $cat = (int) $row->cat_id;

    if ($cat > 0) {
        $counts[$cat] = ($counts[$cat] ?? 0) + (int) $row->episode_count;
    }
}

$missing = [];

foreach (array_keys($cids) as $cid) {
    if (($counts[$cid] ?? 0) === 0) {
        $missing[$cid] = true;
    }
}

foreach (array_keys($problem) as $cid) {
    $missing[$cid] = true;
}

$missing = array_keys($missing);
usort($missing, static fn (int $a, int $b): int => $a <=> $b);

file_put_contents($outFile, implode(',', $missing));

printf("cid_file=%d\n", count($cids));
printf("problem_cids=%d\n", count($problem));
printf("missing_zero_or_problem=%d\n", count($missing));

if ($missing !== []) {
    printf("missing_sample=%s\n", implode(',', array_slice($missing, 0, 100)));
} else {
    echo "missing_sample=-\n";
}

if ($problem !== []) {
    printf("problem_sample=%s\n", implode(',', array_slice(array_keys($problem), 0, 50)));
} else {
    echo "problem_sample=-\n";
}
