<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/SyncGenre.php
 * Fungsi : sync katalog per genre lokal, append-only
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UpsertsAnimekuItem;
use App\Models\Genre;
use App\Models\SyncState;
use App\Services\Animeku\AnimekuClient;
use App\Services\Animeku\ResponseMapper;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class SyncGenre extends Command
{
    use UpsertsAnimekuItem;

    protected $signature = 'sync:genre
                            {--genre= : Genre spesifik}
                            {--pages=1 : Jumlah page per genre}
                            {--count=20 : Item per page}';

    protected $description = 'Sinkron katalog per genre dari Animeku';

    public function handle(AnimekuClient $client, ResponseMapper $mapper): int
    {
        $pages = max(1, (int) ($this->option('pages') ?: 1));
        $count = max(1, (int) ($this->option('count') ?: 20));
        $specific = trim((string) $this->option('genre'));

        if ($specific !== '') {
            $genres = collect([
                (object) [
                    'slug' => $specific,
                    'external_key' => $specific,
                ],
            ]);
        } else {
            $genres = Genre::query()
                ->limit(10)
                ->get(['id', 'name', 'slug', 'external_key']);
        }

        $status = 'ok';
        $error = null;
        $processed = 0;

        foreach ($genres as $genre) {
            $key = trim((string) ($genre->external_key ?: $genre->slug));

            if ($key === '') {
                continue;
            }

            for ($page = 1; $page <= $pages; $page++) {
                try {
                    $response = $client->genre($key, $count);
                    $items = $response['latest_anime'] ?? $response['data'] ?? [];

                    if (!is_array($items)) {
                        throw new RuntimeException('invalid_genre_payload');
                    }

                    foreach ($items as $item) {
                        if (is_array($item)) {
                            $this->upsertItem($item, $mapper);
                            $processed++;
                        }
                    }
                } catch (Throwable $e) {
                    $status = $processed > 0 ? 'partial' : 'fail';
                    $error = $e->getMessage();
                    break 2;
                }
            }
        }

        SyncState::query()->updateOrCreate(
            ['job_key' => 'sync:genre'],
            [
                'last_page_ok' => $processed > 0 ? 1 : 0,
                'cursor' => (string) $processed,
                'last_status' => $status,
                'last_run_at' => now(),
                'meta' => [
                    'pages' => $pages,
                    'count' => $count,
                    'processed' => $processed,
                    'error' => $error,
                ],
            ]
        );

        $this->info("sync:genre status={$status} processed={$processed}");

        return $status === 'ok' || $processed > 0 ? self::SUCCESS : self::FAILURE;
    }
}
