<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/RepairAnimeType.php
 * Fungsi : normalisasi kolom anime.type dari teks sinopsis ("Information Type: X")
 * Rujuk  : perbaikan filter Movie, 2026-10-20
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anime;
use Illuminate\Console\Command;

final class RepairAnimeType extends Command
{
    protected $signature = 'zo:repair-type {--dry-run : Tampilkan rencana tanpa menulis ke DB}';

    protected $description = 'Normalisasi kolom anime.type dari sinopsis (Information Type: Movie/TV/OVA/...)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $counts = [];

        Anime::query()->chunkById(200, function ($rows) use (&$changed, &$counts, $dry): void {
            foreach ($rows as $anime) {
                $type = $this->normalize((string) ($anime->synopsis ?? ''), (string) ($anime->type ?? ''));

                if ($type === null || $type === (string) ($anime->type ?? '')) {
                    continue;
                }

                $counts[$type] = ($counts[$type] ?? 0) + 1;
                $changed++;

                if (!$dry) {
                    $anime->type = $type;
                    $anime->save();
                }
            }
        });

        foreach ($counts as $type => $count) {
            $this->line(sprintf('  %-8s %d baris', $type, $count));
        }

        $this->info(($dry ? 'DRY-RUN: ' : '') . "repair-type selesai, {$changed} baris dinormalisasi.");

        return self::SUCCESS;
    }

    private function normalize(string $synopsis, string $current): ?string
    {
        if (preg_match('/Information\s+Type:\s*([A-Za-z]+)/i', $synopsis, $m) === 1) {
            $candidate = strtolower($m[1]);
        } else {
            $candidate = '';
        }

        $type = match ($candidate) {
            'movie' => 'Movie',
            'tv' => 'TV',
            'ova' => 'OVA',
            'ona' => 'ONA',
            'special' => 'Special',
            'music' => 'Music',
            default => null,
        };

        if ($type !== null) {
            return $type;
        }

        $keep = strtolower($current);
        $known = ['tv', 'movie', 'ova', 'ona', 'special', 'music'];

        return in_array($keep, $known, true) ? ucfirst($keep) : 'TV';
    }
}
