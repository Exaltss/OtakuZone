<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Web/WatchController.php
 * Fungsi : halaman player episode lokal, tanpa membocorkan quality_urls ke frontend
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.3, §6, §9
 */
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\Episode;
use App\Services\Stream\QualityResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WatchController extends Controller
{
    public function show(Request $request, string $episode, QualityResolver $qualityResolver): Response
    {
        $episodeModel = Episode::query()
            ->visible()
            ->find((int) $episode);

        if ($episodeModel === null) {
            abort(404);
        }

        $anime = Anime::query()
            ->visible()
            ->find($episodeModel->anime_id);

        if ($anime === null) {
            abort(404);
        }

        $qualityMap = $episodeModel->quality_urls ?? [];
        $available = $qualityResolver->available(is_array($qualityMap) ? $qualityMap : []);
        $default = $qualityResolver->resolve(is_array($qualityMap) ? $qualityMap : [], null);

        return Inertia::render('Watch/Index', [
            'anime' => [
                'id' => (int) $anime->id,
                'slug' => (string) $anime->slug,
                'title' => (string) $anime->title,
            ],
            'episode' => [
                'id' => (int) $episodeModel->id,
                'number' => (float) $episodeModel->number,
                'title' => (string) $episodeModel->title,
                'duration_sec' => $episodeModel->duration_sec === null ? null : (int) $episodeModel->duration_sec,
            ],
            'qualities' => $available,
            'defaultQuality' => $default,
        ]);
    }
}
