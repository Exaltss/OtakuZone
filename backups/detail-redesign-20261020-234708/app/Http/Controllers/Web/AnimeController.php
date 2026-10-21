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
use App\Services\Stream\QualityResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnimeController extends Controller
{
    /**
     * Urutan kunci metadata MAL yang menempel di depan kolom synopsis.
     * Alternation disusun agar 'Information Type' tidak tertukar dengan kunci lain.
     */
    private const META_PATTERN = '/(Alternative Titles|Synonyms|Japanese|English|Information Type|Episodes|Status|Aired|Premiered|Broadcast|Producers|Licensors|Studios|Source|Genres|Themes|Theme|Demographic|Duration|Rating)\s*:/u';

    private const META_LABELS = [
        'Alternative Titles' => 'Judul alternatif',
        'Synonyms' => 'Sinonim',
        'Japanese' => 'Judul asli',
        'English' => 'Judul English',
        'Information Type' => 'Tipe',
        'Episodes' => 'Episode',
        'Status' => 'Status',
        'Aired' => 'Tayang',
        'Premiered' => 'Musim',
        'Broadcast' => 'Jadwal siaran',
        'Producers' => 'Produser',
        'Licensors' => 'Lisensi',
        'Studios' => 'Studio',
        'Source' => 'Adaptasi',
        'Genres' => 'Genre',
        'Themes' => 'Tema',
        'Theme' => 'Tema',
        'Demographic' => 'Demografis',
        'Duration' => 'Durasi',
        'Rating' => 'Rating konten',
    ];

    private const META_NOISE = ['unknown', 'none', 'none found, add some', 'not available', '?'];

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

        $parsed = $this->parseSynopsis($anime->synopsis);

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
     * Pisahkan blok metadata MAL dari sinopsis asli tanpa menebak-nebak.
     * Sinopsis asli hanya diambil bila marker " Sinopsis " benar-benar ada;
     * selain itu dianggap tidak tersedia daripada menampilkan metadata sebagai sinopsis.
     *
     * @return array{synopsis: string|null, genres: array<int,string>, meta: array<int,array{label:string,value:string}>}
     */
    private function parseSynopsis(?string $raw): array
    {
        $text = trim((string) $raw);

        if ($text === '') {
            return ['synopsis' => null, 'genres' => [], 'meta' => []];
        }

        $hasMeta = preg_match(self::META_PATTERN, $text) === 1;

        if (!$hasMeta) {
            return ['synopsis' => $text, 'genres' => [], 'meta' => []];
        }

        $markerStart = false;
        $markerEnd = false;

        // Marker pemisah bisa "Sinopsis" (ID) atau "Synopsis" (EN),
        // dan pemisah sekitarnya kadang NBSP (U+00A0), bukan spasi biasa.
        if (preg_match('/[\s\x{00A0}]+S[iny]nopsis[\s\x{00A0}]+/u', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $markerStart = (int) $m[0][1];
            $markerEnd = $markerStart + strlen((string) $m[0][0]);
        }

        $metaSource = $markerStart === false ? $text : substr($text, 0, $markerStart);
        $synopsis = $markerEnd === false ? null : trim(substr($text, $markerEnd));

        if ($synopsis === '') {
            $synopsis = null;
        }

        $values = [];
        $matches = [];

        if (preg_match_all(self::META_PATTERN, $metaSource, $m2, PREG_OFFSET_CAPTURE) > 0) {
            $matches = $m2[1];
        }

        $count = count($matches);

        for ($i = 0; $i < $count; $i++) {
            $key = (string) $matches[$i][0];
            $valueStart = (int) $matches[$i][1] + strlen($key) + 1;

            // lewati karakter pemisah ": " setelah kunci
            while ($valueStart < strlen($metaSource) && $metaSource[$valueStart] === ' ') {
                $valueStart++;
            }

            $valueEnd = $i + 1 < $count ? (int) $matches[$i + 1][1] : strlen($metaSource);
            $value = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', substr($metaSource, $valueStart, max(0, $valueEnd - $valueStart))) ?? '');

            if ($value === '') {
                continue;
            }

            $values[$key] = $value;
        }

        $meta = [];

        foreach (self::META_LABELS as $key => $label) {
            $value = $values[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (in_array(mb_strtolower($value), self::META_NOISE, true)) {
                continue;
            }

            $meta[] = ['label' => $label, 'value' => $value];
        }

        $genres = [];

        if (isset($values['Genres'])) {
            foreach (explode(',', $values['Genres']) as $genre) {
                $genre = trim($genre);

                if ($genre !== '' && !in_array(mb_strtolower($genre), self::META_NOISE, true)) {
                    $genres[] = $genre;
                }
            }
        }

        return ['synopsis' => $synopsis, 'genres' => $genres, 'meta' => $meta];
    }
}
