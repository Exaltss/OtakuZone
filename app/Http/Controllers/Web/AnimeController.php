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
use App\Models\Rating;
use App\Services\Stream\QualityResolver;
use App\Support\SynopsisParser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnimeController extends Controller
{
    public function show(Request $request, string $slug, QualityResolver $qualityResolver): Response
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
                'quality_urls',
            ]);

        $parsed = SynopsisParser::parse($anime->synopsis);

        $first = $episodes->first();
        $initialQualities = [];

        if ($first !== null) {
            $map = $first->quality_urls ?? [];
            $initialQualities = $qualityResolver->available(is_array($map) ? $map : []);
        }

        return Inertia::render('Anime/Show', [
            'anime' => [
                'id' => (int) $anime->id,
                'slug' => (string) $anime->slug,
                'title' => (string) $anime->title,
                'type' => $anime->type,
                'status' => $anime->status,
                'year' => $anime->year,
                'season' => $anime->season,
                'rating_avg' => $anime->rating_avg,
                'rating_count' => (int) Rating::query()->where('anime_id', $anime->id)->count(),
                'rating_has_mine' => $this->actorHasRated($request, (int) $anime->id),
                'vote_avg' => $anime->vote_avg === null ? null : (float) $anime->vote_avg,
                'vote_count' => (int) ($anime->vote_count ?? 0),
                'view_count' => $anime->view_count,
                'cat_id' => $anime->cat_id,
                'synopsis' => $parsed['synopsis'],
                'genres' => $parsed['genres'],
                'meta' => $parsed['meta'],
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
            'initialQualities' => $initialQualities,
            'signMode' => app()->environment('local') ? 'local' : 'guest',
        ]);
    }

    /**
     * Apakah aktor saat ini (user login atau guest via guest_key) sudah
     * memberi rating untuk anime ini? Dipakai UI untuk menonaktifkan tombol.
     */
    private function actorHasRated(Request $request, int $animeId): bool
    {
        $user = $request->user();

        return Rating::query()
            ->where('anime_id', $animeId)
            ->where(static function ($q) use ($user, $request): void {
                if ($user !== null) {
                    $q->where('user_id', (int) $user->id);
                } else {
                    $q->where('guest_key', substr(hash('sha256', $request->ip() . '|' . $request->session()->getId()), 0, 64));
                }
            })
            ->exists();
    }
}
