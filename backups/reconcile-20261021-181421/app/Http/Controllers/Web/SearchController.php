<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $rawQuery = trim((string) $request->query('q', ''));
        $perPage = 48;
        $page = max(1, (int) $request->query('page', 1));

        $items = collect();
        $total = 0;
        $mode = 'idle';

        if ($rawQuery !== '') {
            $booleanTerm = $this->sanitizeBooleanQuery($rawQuery);
            $query = Anime::query()->visible();

            $usedFulltext = false;
            if ($booleanTerm !== '' && mb_strlen($rawQuery) >= 3) {
                try {
                    $ftQuery = (clone $query)->whereRaw(
                        'MATCH(title, synopsis) AGAINST(? IN BOOLEAN MODE)',
                        [$booleanTerm]
                    );
                    $ftCount = (clone $ftQuery)->count();
                    if ($ftCount > 0) {
                        $query = $ftQuery;
                        $total = $ftCount;
                        $usedFulltext = true;
                        $mode = 'fulltext';
                    }
                } catch (Throwable) {
                    $usedFulltext = false;
                }
            }

            if (!$usedFulltext) {
                $like = '%' . $rawQuery . '%';
                $query->where(static function ($q) use ($like): void {
                    $q->where('title', 'like', $like)
                        ->orWhere('synopsis', 'like', $like);
                });
                $total = (clone $query)->count();
                $mode = 'like';
            }

            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            $items = $query
                ->orderByDesc('view_count')
                ->orderBy('title')
                ->offset($offset)
                ->limit($perPage)
                ->get([
                    'id',
                    'slug',
                    'title',
                    'type',
                    'status',
                    'year',
                    'rating_avg',
                    'view_count',
                    'cat_id',
                    'image_path',
                    'episodes_total',
                ]);
        } else {
            $lastPage = 1;
            $offset = 0;
        }

        return Inertia::render('Search/Index', [
            'q' => $rawQuery,
            'mode' => $mode,
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ]);
    }

    /**
     * Pencarian cepat (live) untuk dropdown navbar: JSON ringan + URL poster.
     * Rujuk: rekonsiliasi navbar tunggal, pencarian-as-you-type.
     */
    public function quick(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['items' => []]);
        }

        $like = '%' . $q . '%';

        $rows = Anime::query()
            ->visible()
            ->where(static function ($search) use ($like): void {
                $search->where('title', 'like', $like)
                    ->orWhere('synopsis', 'like', $like);
            })
            ->orderByDesc('popularity_score')
            ->orderBy('title')
            ->limit(12)
            ->get(['id', 'slug', 'title', 'type', 'status', 'year', 'rating_avg', 'image_path']);

        return response()->json([
            'items' => $rows->map(static fn (Anime $anime): array => [
                'id' => (int) $anime->id,
                'slug' => (string) $anime->slug,
                'title' => (string) $anime->title,
                'type' => $anime->type,
                'status' => $anime->status,
                'year' => $anime->year,
                'rating_avg' => $anime->rating_avg === null ? null : (float) $anime->rating_avg,
                'poster' => zonaotaku_poster_url($anime->image_path),
            ])->values()->all(),
        ]);
    }

    private function sanitizeBooleanQuery(string $input): string
    {
        $cleaned = (string) preg_replace('/[^\p{L}\p{N}\s+\-"*()]+/u', ' ', $input);
        $tokens = array_values(array_filter(explode(' ', trim($cleaned))));

        if ($tokens === []) {
            return '';
        }

        $out = [];
        foreach (array_slice($tokens, 0, 8) as $token) {
            $bare = trim($token, '+-*"()');
            if (mb_strlen($bare) >= 2) {
                $out[] = '+' . $bare . '*';
            }
        }

        return implode(' ', $out);
    }
}
