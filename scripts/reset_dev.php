<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/reset_dev.php
 * Fungsi : reset tabel dev lokal sebelum full catalog bersih
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.4, §13.5
 */
declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = [
    'watch_history',
    'episodes',
    'watchlists',
    'ratings',
    'group_items',
    'anime',
    'sync_state',
];

DB::statement('SET FOREIGN_KEY_CHECKS=0');

foreach ($tables as $table) {
    DB::statement("TRUNCATE TABLE `{$table}`");
}

DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo "OK: tabel dev reset.\n";
