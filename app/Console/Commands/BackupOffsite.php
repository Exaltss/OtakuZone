<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/BackupOffsite.php
 * Fungsi : dump DB + archive aset + encrypt lokal, upload R2 bila disk siap
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §9, §11.3, §10.1
 */
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class BackupOffsite extends Command
{
    protected $signature = 'backup:offsite
                            {--keep-days=7 : Retensi backup lokal}
                            {--no-r2 : Lewati upload offsite}
                            {--dry-run : Hitung rencana tanpa dump/upload}';

    protected $description = 'Buat backup terenkripsi lokal dan upload ke R2 bila tersedia';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepDays = max(1, (int) $this->option('keep-days'));
        $noR2 = (bool) $this->option('no-r2');

        $backupDir = storage_path('app/backups');
        @mkdir($backupDir, 0775, true);

        $ts = now()->format('Ymd_His');
        $sqlName = "zonaotaku-{$ts}.sql";
        $tarName = "zonaotaku-{$ts}.tar.gz";
        $encName = "zonaotaku-{$ts}.tar.gz.enc";

        $sqlPath = $backupDir . '/' . $sqlName;
        $tarPath = $backupDir . '/' . $tarName;
        $encPath = $backupDir . '/' . $encName;

        if ($dryRun) {
            $this->line("DRY_RUN sql={$sqlPath}");
            $this->line("DRY_RUN archive={$tarPath}");
            $this->line("DRY_RUN encrypted={$encPath}");
            $this->line('DRY_RUN r2=' . ($noR2 ? 'skipped' : 'if-configured'));

            return self::SUCCESS;
        }

        $this->dumpDatabase($sqlPath);
        $this->createArchive($tarPath, $backupDir, $sqlName);
        $this->encryptArchive($tarPath, $encPath);

        @unlink($sqlPath);
        @unlink($tarPath);

        $this->rotateLocal($backupDir, $keepDays);

        if (!$noR2) {
            $this->uploadR2($encPath);
        }

        $this->info("backup:offsite ok file=" . basename($encPath));

        return self::SUCCESS;
    }

    private function dumpDatabase(string $sqlPath): void
    {
        $db = config('database.connections.mysql');

        if (!is_array($db)) {
            throw new RuntimeException('config_database_mysql_missing');
        }

        $process = new Process(
            [
                'mysqldump',
                '--host=' . (string) ($db['host'] ?? '127.0.0.1'),
                '--port=' . (string) ($db['port'] ?? '3306'),
                '--user=' . (string) ($db['username'] ?? ''),
                '--single-transaction',
                '--quick',
                '--lock-tables=false',
                '--set-gtid-purged=OFF',
                (string) ($db['database'] ?? ''),
            ],
            null,
            ['MYSQL_PWD' => (string) ($db['password'] ?? '')],
            null,
            300
        );

        $fh = fopen($sqlPath, 'wb');

        if ($fh === false) {
            throw new RuntimeException('cannot_open_sql_backup');
        }

        $process->run(function (string $type, string $buffer) use ($fh): void {
            if ($type === Process::OUT) {
                fwrite($fh, $buffer);
            }
        });

        fclose($fh);

        if (!$process->isSuccessful()) {
            @unlink($sqlPath);

            throw new RuntimeException('mysqldump_failed: ' . trim($process->getErrorOutput()));
        }
    }

    private function createArchive(string $tarPath, string $backupDir, string $sqlName): void
    {
        $args = ['tar', '-czf', $tarPath];

        $storageApp = storage_path('app');
        $publicDir = $storageApp . '/public';

        if (is_dir($publicDir)) {
            $args[] = '--exclude=cache';
            $args[] = '-C';
            $args[] = $storageApp;
            $args[] = 'public';
        }

        $args[] = '-C';
        $args[] = base_path();
        $args[] = 'config';

        $args[] = '-C';
        $args[] = $backupDir;
        $args[] = $sqlName;

        $process = new Process($args, null, null, null, 600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('tar_failed: ' . trim($process->getErrorOutput()));
        }
    }

    private function encryptArchive(string $tarPath, string $encPath): void
    {
        $key = (string) config('zonaotaku.backup.encrypt_key', '');

        if ($key === '') {
            throw new RuntimeException('BACKUP_ENCRYPT_KEY_missing');
        }

        $process = new Process(
            [
                'openssl',
                'enc',
                '-aes-256-cbc',
                '-pbkdf2',
                '-salt',
                '-pass',
                'env:ZO_BACKUP_KEY',
                '-in',
                $tarPath,
                '-out',
                $encPath,
            ],
            null,
            ['ZO_BACKUP_KEY' => $key],
            null,
            600
        );

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('openssl_encrypt_failed: ' . trim($process->getErrorOutput()));
        }
    }

    private function rotateLocal(string $backupDir, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->timestamp;

        foreach (glob($backupDir . '/*.tar.gz.enc') ?: [] as $file) {
            if (is_file($file) && filemtime($file) !== false && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function uploadR2(string $encPath): void
    {
        $disk = config('filesystems.disks.r2');

        if (!is_array($disk) || empty($disk['key']) || empty($disk['secret'])) {
            $this->warn('R2 disk belum dikonfigurasi. Backup lokal tetap aman.');

            return;
        }

        try {
            Storage::disk('r2')->putFileAs('backups', $encPath, basename($encPath));
        } catch (Throwable $e) {
            $this->warn('R2 upload dilewati: ' . $e->getMessage());
        }
    }
}
