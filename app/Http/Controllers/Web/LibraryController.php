<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\WatchHistory;
use App\Models\Watchlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LibraryController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('auth.login');
        }

        $watchlists = Watchlist::query()
            ->with(['anime:id,slug,title,type,status,year,rating_avg,image_path,episodes_total'])
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get()
            ->filter(static fn (Watchlist $w): bool => $w->anime !== null)
            ->map(static fn (Watchlist $w): array => [
                'id' => (int) $w->id,
                'status' => (string) $w->status,
                'updated_at' => $w->updated_at?->diffForHumans(),
                'anime' => [
                    'id' => (int) $w->anime->id,
                    'slug' => (string) $w->anime->slug,
                    'title' => (string) $w->anime->title,
                    'type' => $w->anime->type,
                    'status' => $w->anime->status,
                    'year' => $w->anime->year,
                    'rating_avg' => $w->anime->rating_avg,
                    'image_path' => $w->anime->image_path,
                    'episodes_total' => $w->anime->episodes_total,
                ],
            ])
            ->values();

        $history = WatchHistory::query()
            ->with(['episode:id,anime_id,number,title,duration_sec', 'episode.anime:id,slug,title,image_path'])
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->filter(static fn (WatchHistory $h): bool => $h->episode !== null && $h->episode->anime !== null)
            ->map(static fn (WatchHistory $h): array => [
                'id' => (int) $h->id,
                'position_sec' => (int) $h->position_sec,
                'duration_sec' => $h->duration_sec ? (int) $h->duration_sec : ($h->episode->duration_sec ? (int) $h->episode->duration_sec : null),
                'completed' => $h->completed_at !== null,
                'updated_at' => $h->updated_at?->diffForHumans(),
                'episode' => [
                    'id' => (int) $h->episode->id,
                    'number' => (float) $h->episode->number,
                    'title' => (string) $h->episode->title,
                ],
                'anime' => [
                    'id' => (int) $h->episode->anime->id,
                    'slug' => (string) $h->episode->anime->slug,
                    'title' => (string) $h->episode->anime->title,
                    'image_path' => $h->episode->anime->image_path,
                ],
            ])
            ->values();

        $ratings = Rating::query()
            ->with(['anime:id,slug,title,type,year,rating_avg,image_path'])
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get()
            ->filter(static fn (Rating $r): bool => $r->anime !== null)
            ->map(static fn (Rating $r): array => [
                'id' => (int) $r->id,
                'score' => (int) $r->score,
                'review_text' => $r->review_text,
                'updated_at' => $r->updated_at?->diffForHumans(),
                'anime' => [
                    'id' => (int) $r->anime->id,
                    'slug' => (string) $r->anime->slug,
                    'title' => (string) $r->anime->title,
                    'type' => $r->anime->type,
                    'year' => $r->anime->year,
                    'rating_avg' => $r->anime->rating_avg,
                ],
            ])
            ->values();

        return Inertia::render('Library/Index', [
            'watchlists' => $watchlists,
            'history' => $history,
            'ratings' => $ratings,
        ]);
    }
}
