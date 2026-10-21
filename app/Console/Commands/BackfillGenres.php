<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/BackfillGenres.php
 * Fungsi : isi anime.genres_csv + tabel genres dari synopsis / raw_meta
 * Rujuk  : fitur filter genre; append-only, aman dijalankan ulang
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

    protected $description = 'Isi anime.genres_csv dan tabel genres dari metadata MAL pada synopsis (fallback raw_meta.genre)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $skipped = 0;
        $genreNames = [];
        $dropped = [];

        Anime::query()->orderBy('id')->chunkById(200, function ($rows) use (&$updated, &$skipped, &$genreNames, &$dropped, $dryRun): void {
            foreach ($rows as $anime) {
                $tokens = SynopsisParser::genreTokens($anime->synopsis, $anime->raw_meta);

                $kept = [];

                foreach ($tokens as $token) {
                    $canon = SynopsisParser::canonGenre($token);

                    if ($canon !== null) {
                        $kept[] = $canon;

                        continue;
                    }

                    // catat token tak dikenal yang "masuk akal" (bukan paragraf rusak)
                    if (
                        mb_strlen($token) <= 30
                        && str_word_count($token) <= 3
                        && strpbrk($token, '.":') === false
                    ) {
                        $dropped[$token] = ($dropped[$token] ?? 0) + 1;
                    }
                }

                $kept = array_values(array_unique($kept));
                $csv = implode(',', $kept);

                foreach ($kept as $genre) {
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
        $pruned = 0;

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

            $pruned = Genre::query()->whereNotIn('slug', $slugs === [] ? [''] : $slugs)->delete();
        } else {
            $genreCount = count($genreNames);
        }

        $this->info(sprintf(
            'zo:backfill-genres dry_run=%s anime_updated=%d anime_unchanged=%d genre_distinct=%d genre_pruned=%d',
            $dryRun ? 'true' : 'false',
            $updated,
            $skipped,
            $genreCount,
            $pruned
        ));

        if ($dropped !== []) {
            arsort($dropped);
            $top = array_slice($dropped, 0, 15, true);

            $this->warn('Token genre TIDAK dikenal (dibuang). Bila ada yang seharusnya genre sah, tambahkan ke GENRE_CANON:');

            foreach ($top as $token => $count) {
                $this->line(sprintf('   - %-28s %d anime', $token, $count));
            }
        }

        return self::SUCCESS;
    }
}
