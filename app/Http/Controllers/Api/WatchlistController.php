<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiErrors;
use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\Watchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class WatchlistController extends Controller
{
    use ApiErrors;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->apiError(401, 'unauthenticated', 'Silakan login terlebih dahulu.');
        }

        $validator = Validator::make($request->all(), [
            'anime_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:watching,completed,on_hold,dropped,plan_to_watch'],
        ]);

        if ($validator->fails()) {
            return $this->apiError(422, 'validation_failed', implode('; ', $validator->errors()->all()));
        }

        $animeId = (int) $request->input('anime_id');
        if (!Anime::query()->visible()->whereKey($animeId)->exists()) {
            return $this->apiError(404, 'anime_not_found', 'Anime tidak ditemukan.');
        }

        $item = Watchlist::query()->updateOrCreate(
            [
                'user_id' => (int) $user->id,
                'anime_id' => $animeId,
            ],
            [
                'status' => (string) $request->input('status'),
            ]
        );

        return response()->json([
            'ok' => true,
            'status' => $item->status,
        ]);
    }

    public function destroy(Request $request, int $animeId): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->apiError(401, 'unauthenticated', 'Silakan login terlebih dahulu.');
        }

        Watchlist::query()
            ->where('user_id', (int) $user->id)
            ->where('anime_id', $animeId)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
