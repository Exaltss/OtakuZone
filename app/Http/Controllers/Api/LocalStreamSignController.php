<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/LocalStreamSignController.php
 * Fungsi : sign PLAY token lokal-only untuk tes streaming tanpa auth UI
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §4.10, §7.3
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

final class LocalStreamSignController extends Controller
{
    use ApiErrors;

    public function store(
        Request $request,
        TokenService $tokens,
        QualityResolver $qualityResolver,
        AuditWriter $audit
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'episode_id' => ['required', 'integer', 'min:1'],
            'quality' => ['nullable', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            return $this->apiError(422, 'validation_failed', implode('; ', $validator->errors()->all()));
        }

        $episodeId = (int) $request->input('episode_id');
        $episode = Episode::query()->visible()->find($episodeId);

        if ($episode === null) {
            return $this->apiError(404, 'episode_not_found', 'Episode tidak ditemukan.');
        }

        $qualityMap = $episode->quality_urls ?? [];

        if (!is_array($qualityMap) || $qualityMap === []) {
            return $this->apiError(410, 'quality_unavailable', 'Stream episode tidak tersedia.');
        }

        $available = $qualityResolver->available($qualityMap);

        if ($available === []) {
            return $this->apiError(410, 'quality_unavailable', 'Stream episode tidak tersedia.');
        }

        $requested = $request->input('quality');
        $resolved = $qualityResolver->resolve(
            $qualityMap,
            is_string($requested) && $requested !== '' ? $requested : null
        );

        if ($resolved === null) {
            return $this->apiError(410, 'quality_expired', 'Kualitas expire atau tidak tersedia.');
        }

        $admission = AdmissionControl::make();

        if (!$admission->allow(0)) {
            $audit->write('local.stream_admission_reject', 0, 'Episode', $episodeId, [
                'quality' => $resolved,
            ], $request);

            return $this->apiError(503, 'admission_full', 'Server padat, coba lagi.');
        }

        $ttl = (int) config('zonaotaku.stream.token_ttl', 2100);
        $token = $tokens->issuePlayToken(
            $episodeId,
            $resolved,
            0,
            (string) $request->ip(),
            (string) $request->userAgent(),
            $ttl
        );

        $member = 'local:' . $episodeId . ':' . $resolved . ':' . substr(hash('sha256', $token), 0, 16);
        $admission->register(0, $member);

        $audit->write('local.stream_sign', 0, 'Episode', $episodeId, [
            'quality' => $resolved,
            'ttl' => $ttl,
            'available' => $available,
        ], $request);

        return response()->json([
            'play_token' => $token,
            'ttl' => $ttl,
            'qualities' => $available,
            'default_quality' => $resolved,
            'stream_url' => url('/stream/play/' . rawurlencode($token)),
        ]);
    }
}
