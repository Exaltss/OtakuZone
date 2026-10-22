<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\FilterRule;
use App\Models\Genre;
use App\Models\Group;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BrowseController extends Controller
{
    public function index(Request $request): Response
    {
        $letter = strtoupper(trim((string) $request->query('letter', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $type = trim((string) $request->query('type', ''));
        $year = (int) $request->query('year', 0);
        $sort = strtolower(trim((string) $request->query('sort', 'popular')));
        $groupSlug = trim((string) $request->query('group', ''));
        $perPage = 48;
        $page = max(1, (int) $request->query('page', 1));

        $query = Anime::query()->visible();

        // Terapkan filter rules aktif dari DB (scope global/page)
        $rules = FilterRule::query()
            ->where('is_active', true)
            ->whereIn('scope', ['global', 'page'])
            ->orderByDesc('priority')
            ->get();

        foreach ($rules as $rule) {
            $cond = is_array($rule->condition_json) ? $rule->condition_json : [];
            if (!empty($cond['exclude_cat_id']) && is_array($cond['exclude_cat_id'])) {
                $query->whereNotIn('cat_id', array_map('intval', $cond['exclude_cat_id']));
            }
            if (!empty($cond['exclude_status']) && is_array($cond['exclude_status'])) {
                $query->whereNotIn('status', array_map('strval', $cond['exclude_status']));
            }
        }

        if ($letter === '#') {
            $query->whereRaw("title REGEXP '^[^A-Za-z]'");
        } elseif (preg_match('/^[A-Z]$/', $letter) === 1) {
            $query->where('title', 'like', $letter . '%');
        } else {
            $letter = '';
        }

        if (in_array($status, ['ongoing', 'completed'], true)) {
            $query->where('status', $status);
        } else {
            $status = '';
        }

        if ($type !== '') {
            $query->where('type', $type);
        }

        if ($year >= 1970 && $year <= 2100) {
            $query->where('year', $year);
        } else {
            $year = 0;
        }

        if ($groupSlug !== '') {
            $group = Group::query()->where('slug', $groupSlug)->first();
            if ($group !== null) {
                $query->whereIn('id', $group->items()->pluck('anime_id'));
            }
        }

        match ($sort) {
            'title' => $query->orderBy('title'),
            'latest' => $query->orderByDesc('synced_at')->orderByDesc('id'),
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('view_count'),
            'year' => $query->orderByDesc('year')->orderBy('title'),
            default => $query->orderByDesc('popularity_score')->orderByDesc('view_count')->orderBy('title'),
        };

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $items = $query
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

        $years = Anime::query()
            ->visible()
            ->whereNotNull('year')
            ->select('year')
            ->distinct()
            ->orderByDesc('year')
            ->limit(40)
            ->pluck('year')
            ->all();

        $types = Anime::query()
            ->visible()
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        $groups = Group::query()->orderBy('name')->get(['id', 'name', 'slug']);
        $genres = Genre::query()->orderBy('name')->get(['id', 'name', 'slug']);

        return Inertia::render('Browse/Index', [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
            'filters' => [
                'letter' => $letter,
                'status' => $status,
                'type' => $type,
                'year' => $year > 0 ? (string) $year : '',
                'sort' => $sort,
                'group' => $groupSlug,
            ],
            'options' => [
                'years' => $years,
                'types' => $types,
                'groups' => $groups,
                'genres' => $genres,
            ],
        ]);
    }
}
