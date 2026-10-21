<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/BackfillGenres.php
 * Fungsi : isi anime.genres_csv + tabel genres dari blok metadata MAL di synopsis
 * Rujuk  : fitur filter genre katalog; append-only, aman dijalankan ulang
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\Genre;
use App\Support\SynopsisParser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class BackfillGenres extends Command
{
    protected $signature = 'zo:backfill-genres
                            {--dry-run : Hitung saja, jangan tulis DB}';

    protected $description = 'Isi anime.genres_csv dan tabel genres dari metadata MAL pada synopsis';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $skipped = 0;
        $genreNames = [];

        $query = Anime::query()->orderBy('id');

        $query->chunkById(200, function ($rows) use (&$updated, &$skipped, &$genreNames, $dryRun): void {
            foreach ($rows as $anime) {
                $genres = SynopsisParser::genres($anime->synopsis);
                $csv = implode(',', $genres);

                foreach ($genres as $genre) {
                    $genreNames[$genre] = true;
                }

                if ((string) ($anime->genres_csv ?? '') === $csv) {
                    $skipped++;

                    continue;
                }

                if (!$dryRun) {
                    $anime->genres_csv = $csv === '' ? null : $csv;
                    $anime->save();
                }

                $updated++;
            }
        });

        $genreCount = 0;
        $slugs = [];

        if (!$dryRun) {
            foreach (array_keys($genreNames) as $name) {
                $slug = Str::slug($name);

                if ($slug === '') {
                    continue;
                }

                $slugs[] = $slug;

                Genre::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'external_key' => $slug]
                );

                $genreCount++;
            }

            // buang genre sampah sisa backfill sebelumnya yang kini tak terpakai
            $pruned = Genre::query()->whereNotIn('slug', $slugs === [] ? [''] : $slugs)->delete();
        } else {
            $genreCount = count($genreNames);
            $pruned = 0;
        }

        $this->info(sprintf(
            'zo:backfill-genres dry_run=%s anime_updated=%d anime_unchanged=%d genre_distinct=%d genre_pruned=%d',
            $dryRun ? 'true' : 'false',
            $updated,
            $skipped,
            $genreCount,
            $pruned
        ));

        return self::SUCCESS;
    }
}
