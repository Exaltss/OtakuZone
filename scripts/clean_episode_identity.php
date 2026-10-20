<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : scripts/clean_episode_identity.php
 * Fungsi : membersihkan episode parcial dan mengunci identity episode ke anime_id + external_id
 * Rujuk  : Blueprint_ANIMEKU.md §3.2, §4; ZONAOTAKU_V2_FINAL.md §5.4
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::statement('SET FOREIGN_KEY_CHECKS=0');

$before = (int) DB::table('episodes')->count();

DB::table('episodes')->truncate();
DB::table('anime')->update(['episodes_total' => 0]);

$indexes = DB::select("SHOW INDEX FROM `episodes` WHERE Key_name = 'uq_ep_external'");

if ($indexes !== []) {
    DB::statement('ALTER TABLE `episodes` DROP INDEX `uq_ep_external`');
}

DB::statement('ALTER TABLE `episodes` ADD UNIQUE KEY `uq_ep_external` (`anime_id`, `external_id`)');

DB::table('sync_state')
    ->whereIn('job_key', [
        'sync:full',
        'sync:episodes',
        'sync:holes',
        'sync:detail-sweep',
    ])
    ->delete();

DB::statement('SET FOREIGN_KEY_CHECKS=1');

printf("OK: episode parcial dibersihkan. before=%d\n", $before);
