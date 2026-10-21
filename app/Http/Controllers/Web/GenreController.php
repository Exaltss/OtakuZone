<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Web/GenreController.php
 * Fungsi : halaman khusus berisi semua genre + jumlah anime per genre
 * Rujuk  : fitur filter genre; klik genre -> katalog /?genre=
 */
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\Genre;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GenreController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $counts = [];

        Anime::query()
            ->visible()
            ->whereNotNull('genres_csv')
            ->pluck('genres_csv')
            ->each(static function ($csv) use (&$counts): void {
                foreach (explode(',', (string) $csv) as $genre) {
                    $genre = trim($genre);

                    if ($genre !== '') {
                        $counts[$genre] = ($counts[$genre] ?? 0) + 1;
                    }
                }
            });

        $genres = Genre::query()
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(static fn (Genre $genre): array => [
                'name' => (string) $genre->name,
                'slug' => (string) $genre->slug,
                'count' => (int) ($counts[(string) $genre->name] ?? 0),
            ])
            ->filter(static fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();

        return Inertia::render('Genres/Index', [
            'genres' => $genres,
            'stats' => [
                'genre_count' => count($genres),
                'anime_count' => (int) Anime::query()->visible()->whereNotNull('genres_csv')->count(),
            ],
        ]);
    }
}
