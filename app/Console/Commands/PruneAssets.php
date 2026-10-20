<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/PruneAssets.php
 * Fungsi : prune thumbnail yatim, kuota LRU, audit lama
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §5.1, §1.4, §4.7, §4.8
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\AuditLog;
use App\Models\Episode;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use SplFileInfo;

final class PruneAssets extends Command
{
    protected $signature = 'prune
                            {--days=90 : Umur audit log maksimum dalam hari}
                            {--thumb-quota-mb=400 : Kuota folder thumbnail dalam MB}
                            {--orphan-hours=24 : Umur file yatim minimum sebelum dihapus}
                            {--dry-run : Hitung saja, jangan hapus}';

    protected $description = 'Bersihkan aset yatim, enforcing kuota thumbnail, dan prune audit lama';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
        $quotaMb = max(1, (int) $this->option('thumb-quota-mb'));
        $orphanHours = max(1, (int) $this->option('orphan-hours'));

        $auditCutoff = now()->subDays($days);
        $auditQuery = AuditLog::query()->where('created_at', '<', $auditCutoff);
        $auditCount = (clone $auditQuery)->count();

        if (!$dryRun && $auditCount > 0) {
            $auditQuery->delete();
        }

        $thumbDir = storage_path('app/public/thumbnails');

        if (!is_dir($thumbDir)) {
            $this->info("prune ok audit_deleted={$auditCount} thumb_dir=missing");

            return self::SUCCESS;
        }

        $referenced = $this->referencedPaths();
        $files = $this->filesIn($thumbDir);
        $orphanCutoff = now()->subHours($orphanHours)->timestamp;
        $deletedOrphans = 0;
        $kept = collect();

        foreach ($files as $file) {
            $relative = $this->relative($thumbDir, $file->getPathname());
            $basename = $file->getFilename();

            if ($this->isReferenced($relative, $basename, $referenced)) {
                $kept->push($file);
                continue;
            }

            if ($file->getMTime() <= $orphanCutoff) {
                if (!$dryRun) {
                    @unlink($file->getPathname());
                }

                $deletedOrphans++;
                continue;
            }

            $kept->push($file);
        }

        $quotaBytes = $quotaMb * 1024 * 1024;
        $totalBytes = (int) $kept->sum(static fn (SplFileInfo $file): int => (int) ($file->getSize() ?: 0));
        $deletedLru = 0;

        if ($totalBytes > $quotaBytes) {
            $sorted = $kept->sortBy(static fn (SplFileInfo $file): int => (int) $file->getMTime())->values();

            foreach ($sorted as $file) {
                if ($totalBytes <= $quotaBytes) {
                    break;
                }

                $relative = $this->relative($thumbDir, $file->getPathname());
                $basename = $file->getFilename();

                if ($this->isReferenced($relative, $basename, $referenced)) {
                    continue;
                }

                $size = (int) ($file->getSize() ?: 0);

                if (!$dryRun) {
                    @unlink($file->getPathname());
                }

                $totalBytes -= $size;
                $deletedLru++;
            }
        }

        $this->info(sprintf(
            'prune ok audit=%d orphan=%d lru=%d remaining_bytes=%d dry_run=%s',
            $auditCount,
            $deletedOrphans,
            $deletedLru,
            max(0, $totalBytes),
            $dryRun ? 'true' : 'false'
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int,string>
     */
    private function referencedPaths(): Collection
    {
        $anime = Anime::query()
            ->whereNotNull('image_path')
            ->pluck('image_path');

        $episodes = Episode::query()
            ->whereNotNull('thumbnail_path')
            ->pluck('thumbnail_path');

        return $anime
            ->merge($episodes)
            ->map(static fn (mixed $path): string => trim((string) $path))
            ->filter(static fn (string $path): bool => $path !== '')
            ->unique();
    }

    /**
     * @return Collection<int,SplFileInfo>
     */
    private function filesIn(string $dir): Collection
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }

        return collect($files);
    }

    private function relative(string $root, string $path): string
    {
        return ltrim(str_replace($root, '', $path), '/\\');
    }

    /**
     * @param Collection<int,string> $referenced
     */
    private function isReferenced(string $relative, string $basename, Collection $referenced): bool
    {
        foreach ($referenced as $path) {
            $normalized = ltrim(str_replace(['\\', storage_path('app/public/')], ['/', ''], (string) $path), '/');

            if ($normalized === $relative || $basename === basename($normalized)) {
                return true;
            }
        }

        return false;
    }
}
