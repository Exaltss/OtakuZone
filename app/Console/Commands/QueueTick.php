<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/QueueTick.php
 * Fungsi : cron queue worker tanpa daemon, stop-when-empty + max-time
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.2, §10.4, §0.2 LOCK-10
 */
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class QueueTick extends Command
{
    protected $signature = 'zo:queue-tick
                            {--max-time=50 : Batas hidup worker per tick}
                            {--tries=2 : Retry job}
                            {--sleep=1 : Sleep antar job}
                            {--queue=default : Nama queue}';

    protected $description = 'Jalankan queue worker sekali jalan lalu mati, ramah cPanel EP=20';

    public function handle(): int
    {
        $connection = (string) config('queue.default', 'sync');

        if (in_array($connection, ['sync', 'null'], true)) {
            $this->line("queue-tick skipped connection={$connection}");

            return self::SUCCESS;
        }

        $status = Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => max(10, (int) $this->option('max-time')),
            '--tries' => max(1, (int) $this->option('tries')),
            '--sleep' => max(0, (int) $this->option('sleep')),
            '--queue' => (string) $this->option('queue'),
        ]);

        $this->line("queue-tick connection={$connection} status={$status}");

        return $status === 0 ? self::SUCCESS : self::FAILURE;
    }
}
