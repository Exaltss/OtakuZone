<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncLatest.php
 * Fungsi : sync katalog terbaru Animeku dengan checkpoint resume
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §5.4
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UpsertsAnimekuItem;
use App\Models\SyncState;
use App\Services\Animeku\AnimekuClient;
use App\Services\Animeku\ResponseMapper;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class SyncLatest extends Command
{
    use UpsertsAnimekuItem;

    protected $signature = 'sync:latest
                            {--pages= : Jumlah page yang ditarik}
                            {--count= : Item per page}
                            {--reset : Mulai ulang dari page 1}
                            {--now : Alias eksekusi langsung}';

    protected $description = 'Sinkron katalog terbaru dari Animeku ke anime + episodes';

    public function handle(AnimekuClient $client, ResponseMapper $mapper): int
    {
        $pages = max(1, (int) ($this->option('pages') ?: config('zonaotaku.sync.pages', 3)));
        $count = max(1, (int) ($this->option('count') ?: 20));

        $state = SyncState::firstOrNew(['job_key' => 'sync:latest']);
        $reset = (bool) $this->option('reset');

        $start = $reset ? 1 : max(1, (int) $state->last_page_ok + 1);
        $end = $start + $pages - 1;

        $lastOk = $reset ? 0 : (int) $state->last_page_ok;
        $status = 'ok';
        $error = null;

        for ($page = $start; $page <= $end; $page++) {
            try {
                $response = $client->latest($page, $count);
                $items = $response['latest_anime'] ?? $response['data'] ?? [];

                if (!is_array($items)) {
                    throw new RuntimeException('invalid_latest_payload');
                }

                foreach ($items as $item) {
                    if (is_array($item)) {
                        $this->upsertItem($item, $mapper);
                    }
                }

                $lastOk = $page;
            } catch (Throwable $e) {
                $status = $lastOk >= $start ? 'partial' : 'fail';
                $error = $e->getMessage();
                break;
            }
        }

        SyncState::query()->updateOrCreate(
            ['job_key' => 'sync:latest'],
            [
                'last_page_ok' => $lastOk,
                'cursor' => (string) $lastOk,
                'last_status' => $status,
                'last_run_at' => now(),
                'meta' => [
                    'pages' => $pages,
                    'count' => $count,
                    'start' => $start,
                    'end' => $end,
                    'error' => $error,
                ],
            ]
        );

        $this->info("sync:latest status={$status} last_page_ok={$lastOk}");

        return $status === 'ok' || $lastOk >= $start ? self::SUCCESS : self::FAILURE;
    }
}
