<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : database/migrations/2026_10_18_140000_widen_episodes_number_column.php
 * Fungsi : memperlebar episodes.number agar episode >999 tidak rusak
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §3.1, Blueprint_ANIMEKU §4, realita upstream Eps 1217
 */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `episodes` MODIFY `number` DECIMAL(8,2) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `episodes` MODIFY `number` DECIMAL(5,2) NOT NULL');
    }
};
