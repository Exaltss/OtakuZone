<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/ProbeUpstream.php
 * Fungsi : health probe resolver chain Animeku
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.8, §5.1, §5.5
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Animeku\AnimekuClient;
use Illuminate\Console\Command;

final class ProbeUpstream extends Command
{
    protected $signature = 'probe:upstream';

    protected $description = 'Probe upstream Animeku dan simpan status breaker/resolver';

    public function handle(AnimekuClient $client): int
    {
        $results = $client->testConnection();

        $rows = [];
        $anyOk = false;

        foreach ($results as $result) {
            $ok = (bool) ($result['ok'] ?? false);
            $anyOk = $anyOk || $ok;

            $rows[] = [
                (string) ($result['base_url'] ?? '-'),
                $ok ? 'OK' : 'FAIL',
                (string) ($result['ms'] ?? '-'),
                (string) ($result['error'] ?? ''),
            ];
        }

        if ($rows !== []) {
            $this->table(['base_url', 'status', 'ms', 'error'], $rows);
        } else {
            $this->warn('Tidak ada upstream URL untuk diprobe.');
        }

        SyncState::query()->updateOrCreate(
            ['job_key' => 'probe:upstream'],
            [
                'last_page_ok' => $anyOk ? 1 : 0,
                'cursor' => $anyOk ? 'ok' : 'fail',
                'last_status' => $anyOk ? 'ok' : 'fail',
                'last_run_at' => now(),
                'meta' => [
                    'results' => $results,
                ],
            ]
        );

        return $anyOk ? self::SUCCESS : self::FAILURE;
    }
}
