<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Web/AnimeController.php
 * Fungsi : halaman detail anime + daftar episode lokal urut number,id
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §6, §9, Blueprint_ANIMEKU.md §4
 */
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnimeController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $anime = Anime::query()
            ->visible()
            ->where('slug', $slug)
            ->first();

        if ($anime === null) {
            abort(404);
        }

        $episodes = Episode::query()
            ->where('anime_id', $anime->id)
            ->visible()
            ->orderBy('number')
            ->orderBy('id')
            ->get([
                'id',
                'number',
                'title',
                'duration_sec',
            ]);

        return Inertia::render('Anime/Show', [
            'anime' => [
                'id' => (int) $anime->id,
                'slug' => (string) $anime->slug,
                'title' => (string) $anime->title,
                'type' => $anime->type,
                'status' => $anime->status,
                'year' => $anime->year,
                'rating_avg' => $anime->rating_avg,
                'view_count' => $anime->view_count,
                'cat_id' => $anime->cat_id,
                'synopsis' => $anime->synopsis,
                'episodes_total' => (int) ($anime->episodes_total ?? 0),
                'image_path' => zonaotaku_poster_url($anime->image_path),
            ],
            'episodes' => $episodes->map(static fn (Episode $episode): array => [
                'id' => (int) $episode->id,
                'number' => (float) $episode->number,
                'title' => (string) $episode->title,
                'duration_sec' => $episode->duration_sec === null ? null : (int) $episode->duration_sec,
            ])->all(),
            'stats' => [
                'episode_count' => $episodes->count(),
                'max_number' => $episodes->max('number'),
            ],
        ]);
    }
}
