<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/RepairEpisodeNumbering.php
 * Fungsi : memperbaiki nomor episode dari judul Eps/Episode, rapikan duplikat jadi varian .01/.02, dan recalc episodes_total
 * Rujuk  : Blueprint_ANIMEKU.md §3.2, §4; ZONAOTAKU_V2_FINAL.md §5.4
 */
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RepairEpisodeNumbering extends Command
{
    private const INDEX = 'uq_ep';

    protected $signature = 'episodes:repair-numbering
                            {--dry-run : Hitung perubahan tanpa tulis DB}
                            {--sample=20 : Jumlah sample perubahan yang ditampilkan}';

    protected $description = 'Repair penomoran episode Animeku agar urut dan tidak melompat karena auto-next';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sampleLimit = max(1, (int) $this->option('sample'));

        $rows = DB::table('episodes')
            ->select(['id', 'anime_id', 'title', 'number'])
            ->orderBy('anime_id')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $groups[(int) $row->anime_id][] = $row;
        }

        $stats = [
            'anime' => count($groups),
            'episodes' => count($rows),
            'parsed_groups' => 0,
            'changed' => 0,
            'unparsed' => 0,
            'duplicate_fixed' => 0,
        ];

        $updates = [];
        $samples = [];

        foreach ($groups as $animeId => $list) {
            $parsedGroups = [];
            $unparsed = [];

            foreach ($list as $ep) {
                $base = $this->parseNumberFromTitle((string) $ep->title);

                if ($base === null) {
                    $unparsed[] = $ep;
                    continue;
                }

                $key = number_format($base, 2, '.', '');
                $parsedGroups[$key][] = $ep;
            }

            $stats['unparsed'] += count($unparsed);

            uksort(
                $parsedGroups,
                static fn (string $a, string $b): int => (float) $a <=> (float) $b
            );

            $used = [];
            $assigned = [];

            foreach ($parsedGroups as $key => $items) {
                $stats['parsed_groups']++;

                usort(
                    $items,
                    static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id
                );

                $base = (float) $key;
                $slot = 0;

                foreach ($items as $ep) {
                    $desired = $slot === 0
                        ? $base
                        : round($base + (0.01 * $slot), 2);

                    $desired = $this->findFree($desired, $used);

                    if ($desired === null) {
                        continue;
                    }

                    $assigned[(int) $ep->id] = $desired;
                    $used[number_format($desired, 2, '.', '')] = true;
                    $slot++;
                }
            }

            $maxUsed = 0.0;

            foreach (array_keys($used) as $usedKey) {
                $maxUsed = max($maxUsed, (float) $usedKey);
            }

            foreach ($unparsed as $ep) {
                $current = round((float) $ep->number, 2);

                if ($current <= 0) {
                    $current = 0.01;
                }

                $currentKey = number_format($current, 2, '.', '');

                if (!isset($used[$currentKey])) {
                    $assigned[(int) $ep->id] = $current;
                    $used[$currentKey] = true;
                    $maxUsed = max($maxUsed, $current);
                    continue;
                }

                $desired = $this->findFree(max($current, $maxUsed) + 0.01, $used);

                if ($desired === null) {
                    continue;
                }

                $assigned[(int) $ep->id] = $desired;
                $used[number_format($desired, 2, '.', '')] = true;
                $maxUsed = max($maxUsed, $desired);
            }

            foreach ($list as $ep) {
                $current = round((float) $ep->number, 2);
                $desired = $assigned[(int) $ep->id] ?? $current;

                if (abs($desired - $current) < 0.0001) {
                    continue;
                }

                $updates[] = [
                    'id' => (int) $ep->id,
                    'anime_id' => (int) $animeId,
                    'title' => (string) $ep->title,
                    'from' => $current,
                    'to' => $desired,
                ];

                $stats['changed']++;

                if (count($samples) < $sampleLimit) {
                    $samples[] = [
                        (string) $ep->id,
                        (string) $animeId,
                        mb_substr((string) $ep->title, 0, 55),
                        number_format($current, 2, ',', '.'),
                        number_format($desired, 2, ',', '.'),
                    ];
                }
            }
        }

        $this->table(
            ['metric', 'value'],
            [
                ['mode', $dryRun ? 'dry-run' : 'apply'],
                ['anime', (string) $stats['anime']],
                ['episodes', (string) $stats['episodes']],
                ['parsed_groups', (string) $stats['parsed_groups']],
                ['unparsed', (string) $stats['unparsed']],
                ['changed', (string) $stats['changed']],
            ]
        );

        if ($samples !== []) {
            $this->line('Sample perubahan:');
            $this->table(['id', 'anime_id', 'title', 'from', 'to'], $samples);
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        if ($updates === []) {
            $this->info('Tidak ada nomor episode yang perlu diubah.');
            $this->recalcEpisodesTotal();

            return self::SUCCESS;
        }

        $dropped = false;

        if ($this->indexExists(self::INDEX)) {
            DB::statement('ALTER TABLE `episodes` DROP INDEX `uq_ep`');
            $dropped = true;
        }

        try {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as $update) {
                    DB::table('episodes')
                        ->where('id', (int) $update['id'])
                        ->update(['number' => (float) $update['to']]);
                }
            });

            $duplicates = $this->duplicatePairs(20);

            if ($duplicates !== []) {
                $fixed = $this->fixDuplicatePairs($duplicates);
                $stats['duplicate_fixed'] = $fixed;
                $duplicates = $this->duplicatePairs(20);
            }

            if ($duplicates !== []) {
                throw new RuntimeException('Masih ada duplikat anime_id+number setelah repair.');
            }

            if ($dropped && !$this->indexExists(self::INDEX)) {
                DB::statement('ALTER TABLE `episodes` ADD UNIQUE INDEX `uq_ep` (`anime_id`, `number`)');
            }

            $this->recalcEpisodesTotal();

            $this->info(sprintf('OK: %d nomor episode diperbaiki.', $stats['changed']));
        } catch (Throwable $e) {
            if ($dropped && !$this->indexExists(self::INDEX)) {
                try {
                    $duplicates = $this->duplicatePairs(1);

                    if ($duplicates === []) {
                        DB::statement('ALTER TABLE `episodes` ADD UNIQUE INDEX `uq_ep` (`anime_id`, `number`)');
                    }
                } catch (Throwable) {
                    // Biarkan index belum terpasang jika masih ada duplikat.
                }
            }

            $this->error('Repair gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parseNumberFromTitle(string $title): ?float
    {
        $title = str_replace("\xc2\xa0", ' ', $title);

        if (preg_match('/\b(?:eps?|episode|ep)\.?\s*#?\s*(\d+(?:[.,]\d+)?)\b/iu', $title, $m) !== 1) {
            return null;
        }

        $value = str_replace(',', '.', $m[1]);

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number <= 0 || $number > 999999.99) {
            return null;
        }

        return round($number, 2);
    }

    /**
     * @param array<string,true> $used
     */
    private function findFree(float $start, array &$used): ?float
    {
        $number = round(max(0.01, $start), 2);
        $guard = 0;

        while ($number <= 999999.99) {
            $key = number_format($number, 2, '.', '');

            if (!isset($used[$key])) {
                return $number;
            }

            $number = round($number + 0.01, 2);

            if (++$guard > 100000) {
                break;
            }
        }

        return null;
    }

    private function indexExists(string $index): bool
    {
        $rows = DB::select("SHOW INDEX FROM `episodes` WHERE Key_name = '{$index}'");

        return $rows !== [];
    }

    /**
     * @return array<int,object>
     */
    private function duplicatePairs(int $limit): array
    {
        return DB::select("
            SELECT anime_id, number, COUNT(*) AS c
            FROM episodes
            GROUP BY anime_id, number
            HAVING c > 1
            ORDER BY c DESC
            LIMIT {$limit}
        ");
    }

    /**
     * @param array<int,object> $duplicates
     */
    private function fixDuplicatePairs(array $duplicates): int
    {
        $animeIds = [];

        foreach ($duplicates as $row) {
            $animeIds[(int) $row->anime_id] = true;
        }

        $fixed = 0;

        foreach (array_keys($animeIds) as $animeId) {
            $rows = DB::table('episodes')
                ->where('anime_id', $animeId)
                ->orderBy('id')
                ->get(['id', 'number']);

            $used = [];
            $seen = [];
            $max = 0.0;

            foreach ($rows as $row) {
                $number = round((float) $row->number, 2);
                $key = number_format($number, 2, '.', '');
                $used[$key] = true;
                $max = max($max, $number);
            }

            foreach ($rows as $row) {
                $number = round((float) $row->number, 2);
                $key = number_format($number, 2, '.', '');

                if (isset($seen[$key])) {
                    $desired = $this->findFree(max($number, $max) + 0.01, $used);

                    if ($desired === null) {
                        continue;
                    }

                    DB::table('episodes')
                        ->where('id', (int) $row->id)
                        ->update(['number' => $desired]);

                    $used[number_format($desired, 2, '.', '')] = true;
                    $seen[number_format($desired, 2, '.', '')] = true;
                    $max = max($max, $desired);
                    $fixed++;

                    continue;
                }

                $seen[$key] = true;
            }
        }

        return $fixed;
    }

    private function recalcEpisodesTotal(): void
    {
        DB::statement('
            UPDATE anime
            SET episodes_total = (
                SELECT COUNT(*)
                FROM episodes
                WHERE episodes.anime_id = anime.id
            )
        ');
    }
}
