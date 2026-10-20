<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : routes/console.php
 * Fungsi : scheduler final tanpa daemon, sesuai §5.1/§10.4
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §5.2, §10.4
 */
declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('zo:queue-tick')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('probe:upstream')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

Schedule::command('sync:latest')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14);

Schedule::command('sync:popular')
    ->everyThirtyMinutes()
    ->withoutOverlapping(29);

Schedule::command('sync:genre')
    ->hourly()
    ->withoutOverlapping(59);

Schedule::command('prune')
    ->dailyAt('02:00')
    ->withoutOverlapping(120);

Schedule::command('backup:offsite')
    ->sundays()
    ->at('04:00')
    ->withoutOverlapping(180);
