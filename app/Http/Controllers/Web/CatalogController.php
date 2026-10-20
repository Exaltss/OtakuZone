<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Web/CatalogController.php
 * Fungsi : halaman katalog anime lokal untuk inspeksi kelengkapan data
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §6, §9, §8.2
 */
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 48;
        $page = max(1, (int) $request->query('page', 1));

        $query = Anime::query()->visible();

        if ($q !== '') {
            $like = '%' . $q . '%';

            $query->where(static function ($search) use ($like): void {
                $search->where('title', 'like', $like)
                    ->orWhere('synopsis', 'like', $like);
            });
        }

        $total = (clone $query)->count();
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

        $statRow = Anime::query()
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN year IS NULL THEN 1 ELSE 0 END) AS no_year
            ")
            ->first();

        return Inertia::render('Catalog/Index', [
            'items' => $items,
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
            ],
        ]);
    }
}
