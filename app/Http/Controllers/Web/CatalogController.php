<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Web/CatalogController.php
 * Fungsi : beranda portal anime (hero, rilis terbaru, pilihan, populer) + mode jelajah/pencarian
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §6, §9, §8.2
 */
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        $type = trim((string) $request->query('type', ''));
        $genre = trim((string) $request->query('genre', ''));
        $perPage = 48;
        $page = max(1, (int) $request->query('page', 1));

        $home = $q === '' && $sort === '' && $type === '' && $genre === '' && $page === 1;

        $query = Anime::query()->visible();

        if ($q !== '') {
            $like = '%' . $q . '%';

            $query->where(static function ($search) use ($like): void {
                $search->where('title', 'like', $like)
                    ->orWhere('synopsis', 'like', $like)
                    ->orWhere('raw_meta', 'like', $like);
            });
        }

        if ($type !== '') {
            $norm = mb_strtolower($type);

            if ($norm === 'movie') {
                $query->where(static fn ($q) => $q
                    ->whereRaw("LOWER(COALESCE(type, '')) = 'movie'")
                    ->orWhere('synopsis', 'like', '%Information Type: Movie%'));
            } else {
                $query->whereRaw("LOWER(COALESCE(type, '')) = ?", [$norm]);
            }
        }

        if ($genre !== '') {
            $query->whereRaw("FIND_IN_SET(?, COALESCE(genres_csv, '')) > 0", [$genre]);
        }

        [$orderColumn, $orderDir] = match ($sort) {
            'rating' => ['rating_avg', 'desc'],  // dipakai hanya bila bukan blend
            'views' => ['view_count', 'desc'],
            'title' => ['title', 'asc'],
            default => ['synced_at', 'desc'],
        };

        // "Anime terbaik": blend Bayesian antara rating sumber dan vote komunitas
        // (prior C=10 suara). 0 vote = urutan sumber; makin banyak vote, makin
        // besar pengaruh komunitas -> peringkat bergeser secara alami.
        if ($sort === 'rating') {
            $blend = "((vote_count * COALESCE(vote_avg, 0)) + (10 * COALESCE(rating_avg, 0))) / (vote_count + 10)";
            $query->orderByRaw($blend . ' DESC');
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $items = $home
            ? collect()
            : $query
                ->when($sort !== 'rating', fn ($q) => $q->orderBy($orderColumn, $orderDir))
                ->orderBy('title')
                ->offset($offset)
                ->limit($perPage)
                ->get();

        $featured = $home
            ? Anime::query()->visible()
                ->whereNotNull('synopsis')
                ->where('synopsis', '!=', '')
                ->orderByDesc('rating_avg')
                ->limit(2)
                ->get()
            : collect();

        $yearCut = (int) now()->format('Y');

        $latest = $home
            ? (static function () use ($yearCut): Collection {
                // Ambil hanya episode paling baru (scan PK terbalik, terbatas),
                // BUKAN agregat MAX(synced_at) atas SELURUH tabel episodes.
                // Pada katalog ~90k episode, agregat lama memakan ratusan ms
                // tiap load beranda; versi ini O(konstan).
                $recent = Episode::query()
                    ->orderByDesc('id')
                    ->limit(600)
                    ->get(['id', 'anime_id', 'synced_at']);

                $lastSync = [];

                foreach ($recent as $episode) {
                    if (!isset($lastSync[$episode->anime_id])) {
                        $lastSync[$episode->anime_id] = $episode->synced_at;
                    }
                }

                if ($lastSync === []) {
                    return collect();
                }

                return Anime::query()
                    ->visible()
                    ->whereIn('id', array_keys($lastSync))
                    ->where(static fn ($q) => $q->where('year', '>=', $yearCut)->orWhere('status', 'ongoing'))
                    ->get()
                    ->sortByDesc(static fn (Anime $anime): mixed => $lastSync[$anime->id] ?? null)
                    ->take(12)
                    ->values();
            })()
            : collect();
        $picks = $home ? Anime::query()->visible()->orderByDesc('rating_avg')->limit(12)->get() : collect();
        $popular = $home ? Anime::query()->visible()->orderByDesc('view_count')->limit(5)->get() : collect();

        /** @var Collection<int, Anime> $all */
        $all = collect([$featured, $latest, $picks, $popular, $items])->collapse();
        $ids = $all->pluck('id')->unique()->values()->all();

        $agg = Episode::query()
            ->whereIn('anime_id', $ids)
            ->selectRaw('anime_id, COUNT(*) AS ep_count, MAX(number) AS last_number')
            ->groupBy('anime_id')
            ->get()
            ->keyBy('anime_id');

        $latestEpIds = Episode::query()
            ->whereIn('anime_id', $ids)
            ->selectRaw('anime_id, MAX(id) AS eid')
            ->groupBy('anime_id')
            ->pluck('eid', 'anime_id');

        $thumbs = Episode::query()
            ->whereIn('id', $latestEpIds->values()->all())
            ->get(['id', 'anime_id', 'thumbnail_path'])
            ->keyBy('anime_id');

        $card = static function (Anime $anime) use ($agg, $latestEpIds, $thumbs): array {
            $row = $agg->get($anime->id);
            $thumb = $thumbs->get($anime->id);

            return [
                'id' => (int) $anime->id,
                'slug' => (string) $anime->slug,
                'title' => (string) $anime->title,
                'type' => $anime->type,
                'status' => $anime->status,
                'year' => $anime->year,
                'rating_avg' => $anime->rating_avg === null ? null : (float) $anime->rating_avg,
                'poster' => zonaotaku_poster_url($anime->image_path) ?? $thumb?->thumbnail_path,
                'episode_count' => (int) ($row->ep_count ?? 0),
                'last_episode' => $row === null ? null : (float) $row->last_number,
                'latest_episode_id' => (int) ($latestEpIds->get($anime->id) ?? 0),
            ];
        };

        /** @var callable(Collection<int, Anime>): array<int, array<string, mixed>> $mapCards */
        $mapCards = static fn (Collection $rows): array => $rows->map($card)->values()->all();

        $statRow = Anime::query()
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN year IS NULL THEN 1 ELSE 0 END) AS no_year
            ")
            ->first();

        return Inertia::render('Catalog/Index', [
            'mode' => $home ? 'home' : 'browse',
            'items' => $mapCards($items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
            'stats' => [
                'total' => (int) ($statRow->total ?? 0),
                'ongoing' => (int) ($statRow->ongoing ?? 0),
                'completed' => (int) ($statRow->completed ?? 0),
                'no_year' => (int) ($statRow->no_year ?? 0),
            ],
            'filters' => [
                'q' => $q,
                'sort' => $sort,
                'type' => $type,
                'genre' => $genre,
            ],
            'featured' => $featured
                ->map(static fn (Anime $anime): array => $card($anime) + [
                    'synopsis' => $anime->synopsis,
                    'image_path' => zonaotaku_poster_url($anime->image_path),
                ])
                ->values()
                ->all(),
            'latest' => $mapCards($latest),
            'picks' => $mapCards($picks),
            'popular' => $mapCards($popular),
        ]);
    }
}
