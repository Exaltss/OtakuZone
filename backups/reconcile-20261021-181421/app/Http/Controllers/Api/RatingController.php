<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/RatingController.php
 * Fungsi : pemberian rating oleh user/guest (sekali, tidak bisa mengubah),
 *          manajemen rating (ubah/hapus sembarang) khusus admin
 * Rujuk  : rekonsiliasi peran rating; user hanya menambah, admin mengelola
 */
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiErrors;
use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class RatingController extends Controller
{
    use ApiErrors;

    /**
     * User login maupun guest memberi rating SATU kali per anime.
     * Setelah ada, nilainya tidak bisa diubah oleh pemberinya (hanya admin).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'anime_id' => ['required', 'integer', 'min:1'],
            'score' => ['required', 'integer', 'min:1', 'max:10'],
            'review_text' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->apiError(422, 'validation_failed', implode('; ', $validator->errors()->all()));
        }

        $animeId = (int) $request->input('anime_id');
        $anime = Anime::query()->visible()->find($animeId);

        if ($anime === null) {
            return $this->apiError(404, 'anime_not_found', 'Anime tidak ditemukan.');
        }

        $guestKey = $user === null ? $this->guestKey($request) : null;

        // Tanpa batas: guest maupun user login boleh memberi/memperbarui
        // penilaiannya sendiri per anime. Manajemen rating orang lain = admin.
        $rating = Rating::query()->updateOrCreate(
            $user === null
                ? ['guest_key' => $guestKey, 'anime_id' => $animeId, 'user_id' => null]
                : ['user_id' => (int) $user->id, 'anime_id' => $animeId],
            [
                'user_id' => $user === null ? null : (int) $user->id,
                'guest_key' => $guestKey,
                'score' => (int) $request->input('score'),
                'review_text' => $request->filled('review_text') ? trim((string) $request->input('review_text')) : null,
            ]
        );

        return response()->json([
            'ok' => true,
            'score' => (int) $rating->score,
            'rating_avg' => $this->recompute($anime),
        ]);
    }

    /**
     * Admin: mengubah rating siapa pun.
     */
    public function update(Request $request, int $ratingId): JsonResponse
    {
        $rating = Rating::query()->find($ratingId);

        if ($rating === null) {
            return $this->apiError(404, 'rating_not_found', 'Rating tidak ditemukan.');
        }

        $validator = Validator::make($request->all(), [
            'score' => ['required', 'integer', 'min:1', 'max:10'],
            'review_text' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->apiError(422, 'validation_failed', implode('; ', $validator->errors()->all()));
        }

        $rating->score = (int) $request->input('score');
        $rating->review_text = $request->filled('review_text') ? trim((string) $request->input('review_text')) : null;
        $rating->save();

        $anime = Anime::query()->find((int) $rating->anime_id);

        return response()->json([
            'ok' => true,
            'score' => (int) $rating->score,
            'rating_avg' => $anime === null ? null : $this->recompute($anime),
        ]);
    }

    /**
     * Admin: menghapus rating siapa pun.
     */
    public function destroy(Request $request, int $ratingId): JsonResponse
    {
        $rating = Rating::query()->find($ratingId);

        if ($rating === null) {
            return $this->apiError(404, 'rating_not_found', 'Rating tidak ditemukan.');
        }

        $animeId = (int) $rating->anime_id;
        $rating->delete();

        $anime = Anime::query()->find($animeId);

        return response()->json([
            'ok' => true,
            'rating_avg' => $anime === null ? null : $this->recompute($anime),
        ]);
    }

    private function recompute(Anime $anime): ?float
    {
        $avg = Rating::query()->where('anime_id', (int) $anime->id)->avg('score');

        $anime->rating_avg = $avg === null ? null : min(9.99, round((float) $avg, 2));
        $anime->save();

        return $anime->rating_avg === null ? null : (float) $anime->rating_avg;
    }

    private function guestKey(Request $request): string
    {
        return substr(hash('sha256', $request->ip() . '|' . $request->session()->getId()), 0, 64);
    }
}
