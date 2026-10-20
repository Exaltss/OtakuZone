<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/StreamSignController.php
 * Fungsi : konsumsi nonce, validasi episode/kualitas, admission, terbitkan PLAY token
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §4.10, §7.3, §7.4
 */
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiErrors;
use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Services\Security\AuditWriter;
use App\Services\Stream\AdmissionControl;
use App\Services\Stream\QualityResolver;
use App\Services\Stream\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class StreamSignController extends Controller
{
    use ApiErrors;

    public function store(
        Request $request,
        TokenService $tokens,
        QualityResolver $qualityResolver,
        AdmissionControl $admission,
        AuditWriter $audit
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return $this->apiError(401, 'unauthenticated', 'Login diperlukan.');
        }

        $allowed = array_merge(
            ['360', '480', '720', '1080'],
            array_map('strval', (array) config('zonaotaku.animeku.extra_quality_keys', []))
        );

        $validator = Validator::make($request->all(), [
            'episode_id' => ['required', 'integer', 'min:1'],
            'quality' => ['required', 'string', 'in:' . implode(',', $allowed)],
            'nonce' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->apiError(422, 'validation_failed', implode('; ', $validator->errors()->all()));
        }

        $episodeId = (int) $request->input('episode_id');
        $quality = (string) $request->input('quality');
        $nonce = (string) $request->input('nonce');
        $sessionId = (string) $request->session()->getId();

        if (!$tokens->consumeNonce($nonce, (int) $user->id, $sessionId)) {
            $audit->write('stream.denied', (int) $user->id, 'Episode', $episodeId, [
                'stage' => 'nonce',
                'code' => 'nonce_invalid',
            ]);

            return $this->apiError(403, 'nonce_invalid', 'SIGN nonce tidak valid atau sudah dipakai.');
        }

        $episode = Episode::query()->visible()->find($episodeId);

        if ($episode === null) {
            return $this->apiError(404, 'episode_not_found', 'Episode tidak ditemukan.');
        }

        $qualityUrls = $episode->quality_urls ?? [];

        if (!is_array($qualityUrls) || $qualityUrls === []) {
            return $this->apiError(410, 'quality_unavailable', 'Kualitas tidak tersedia.');
        }

        $available = $qualityResolver->available($qualityUrls);

        if ($available === []) {
            return $this->apiError(410, 'quality_unavailable', 'Kualitas tidak tersedia.');
        }

        $resolvedQuality = $qualityResolver->resolve($qualityUrls, $quality);

        if ($resolvedQuality === null) {
            return $this->apiError(410, 'quality_expired', 'Kualitas expire atau tidak tersedia.');
        }

        if (!$admission->allow((int) $user->id)) {
            $audit->write('stream.admission_reject', (int) $user->id, 'Episode', $episodeId, [
                'stage' => 'sign_admission',
                'quality' => $resolvedQuality,
            ]);

            return $this->apiError(503, 'admission_full', 'Server padat, coba lagi.');
        }

        $playToken = $tokens->issuePlayToken(
            $episodeId,
            $resolvedQuality,
            (int) $user->id,
            (string) $request->ip(),
            (string) $request->userAgent()
        );

        $member = 'sign:' . $episodeId . ':' . $resolvedQuality . ':' . substr(hash('sha256', $playToken), 0, 16);
        $admission->register((int) $user->id, $member);

        $audit->write('stream.sign', (int) $user->id, 'Episode', $episodeId, [
            'quality' => $resolvedQuality,
            'ttl' => (int) config('zonaotaku.stream.token_ttl', 2100),
        ]);

        return response()->json([
            'play_token' => $playToken,
            'ttl' => (int) config('zonaotaku.stream.token_ttl', 2100),
            'qualities' => $available,
            'default_quality' => $resolvedQuality,
        ]);
    }
}
