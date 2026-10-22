<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiErrors;
use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\WatchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ProgressController extends Controller
{
    use ApiErrors;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->apiError(401, 'unauthenticated', 'Silakan login terlebih dahulu.');
        }

        $validator = Validator::make($request->all(), [
            'episode_id' => ['required', 'integer', 'min:1'],
            'position_sec' => ['required', 'integer', 'min:0'],
            'duration_sec' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->apiError(422, 'validation_failed', implode('; ', $validator->errors()->all()));
        }

        $episodeId = (int) $request->input('episode_id');
        $episode = Episode::query()->visible()->find($episodeId);

        if ($episode === null) {
            return $this->apiError(404, 'episode_not_found', 'Episode tidak ditemukan.');
        }

        $position = (int) $request->input('position_sec');
        $duration = $request->filled('duration_sec')
            ? (int) $request->input('duration_sec')
            : ($episode->duration_sec !== null ? (int) $episode->duration_sec : null);

        $completedAt = null;
        if ($duration !== null && $duration > 0 && ($position / $duration) >= 0.9) {
            $completedAt = now();
        }

        $history = WatchHistory::query()->updateOrCreate(
            [
                'user_id' => (int) $user->id,
                'episode_id' => $episodeId,
            ],
            [
                'position_sec' => $position,
                'duration_sec' => $duration,
                'completed_at' => $completedAt,
            ]
        );

        return response()->json([
            'ok' => true,
            'position_sec' => (int) $history->position_sec,
            'duration_sec' => $history->duration_sec,
            'completed' => $history->completed_at !== null,
        ]);
    }
}
