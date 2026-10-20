<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncPopular.php
 * Fungsi : sync katalog populer + popularity_score
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1
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

final class SyncPopular extends Command
{
    use UpsertsAnimekuItem;

    protected $signature = 'sync:popular
                            {--pages= : Jumlah page yang ditarik}
                            {--count= : Item per page}
                            {--reset : Mulai ulang dari page 1}';

    protected $description = 'Sinkron katalog populer dari Animeku dan hitung popularity_score';

    public function handle(AnimekuClient $client, ResponseMapper $mapper): int
    {
        $pages = max(1, (int) ($this->option('pages') ?: config('zonaotaku.sync.pages', 3)));
        $count = max(1, (int) ($this->option('count') ?: 20));

        $state = SyncState::firstOrNew(['job_key' => 'sync:popular']);
        $reset = (bool) $this->option('reset');

        $start = $reset ? 1 : max(1, (int) $state->last_page_ok + 1);
        $end = $start + $pages - 1;

        $lastOk = $reset ? 0 : (int) $state->last_page_ok;
        $status = 'ok';
        $error = null;

        for ($page = $start; $page <= $end; $page++) {
            try {
                $response = $client->popular($page, $count);
                $items = $response['latest_anime'] ?? $response['data'] ?? [];

                if (!is_array($items)) {
                    throw new RuntimeException('invalid_popular_payload');
                }

                foreach ($items as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $anime = $this->upsertItem($item, $mapper);
                    $rank = (($page - 1) * $count) + $index;
                    $anime->popularity_score = max(0, 100000 - $rank);
                    $anime->save();
                }

                $lastOk = $page;
            } catch (Throwable $e) {
                $status = $lastOk >= $start ? 'partial' : 'fail';
                $error = $e->getMessage();
                break;
            }
        }

        SyncState::query()->updateOrCreate(
            ['job_key' => 'sync:popular'],
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

        $this->info("sync:popular status={$status} last_page_ok={$lastOk}");

        return $status === 'ok' || $lastOk >= $start ? self::SUCCESS : self::FAILURE;
    }
}
