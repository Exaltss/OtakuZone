<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/GuestStreamSignController.php
 * Fungsi : sign PLAY token untuk visitor tanpa login: nonce session-bound +
 *          throttle per-IP + admission per-IP guest; token tetap AES+HMAC bound IP/UA
 * Rujuk  : guest streaming, 2026-10-20
 */
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiErrors;
use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Services\Security\AuditWriter;
use App\Services\Security\IpHasher;
use App\Services\Stream\AdmissionControl;
use App\Services\Stream\QualityResolver;
use App\Services\Stream\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class GuestStreamSignController extends Controller
{
    use ApiErrors;

    public function store(
        Request $request,
        TokenService $tokens,
        QualityResolver $qualityResolver,
        AdmissionControl $admission,
        AuditWriter $audit,
        IpHasher $hasher
    ): JsonResponse {
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
        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();

        if (!$tokens->consumeNonce($nonce, 0, $sessionId)) {
            $audit->write('guest.stream_denied', 0, 'Episode', $episodeId, [
                'stage' => 'nonce',
                'code' => 'nonce_invalid',
            ], $request);

            return $this->apiError(403, 'nonce_invalid', 'Nonce guest tidak valid atau sudah dipakai.');
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

        $resolved = $qualityResolver->resolve($qualityUrls, $quality);

        if ($resolved === null) {
            return $this->apiError(410, 'quality_expired', 'Kualitas expire atau tidak tersedia.');
        }

        $guestKey = $hasher->hashIpPrefix($ip);

        if (!$admission->allow(0, $guestKey)) {
            $audit->write('guest.stream_admission_reject', 0, 'Episode', $episodeId, [
                'stage' => 'sign_admission',
                'quality' => $resolved,
            ], $request);

            return $this->apiError(503, 'admission_full', 'Server padat, coba lagi.');
        }

        $ttl = (int) config('zonaotaku.stream.guest_token_ttl', 1200);
        $playToken = $tokens->issuePlayToken($episodeId, $resolved, 0, $ip, $ua, $ttl);

        $member = 'guest:' . $episodeId . ':' . $resolved . ':' . substr(hash('sha256', $playToken), 0, 16);
        $admission->register(0, $member, $guestKey);

        $audit->write('guest.stream_sign', 0, 'Episode', $episodeId, [
            'quality' => $resolved,
            'ttl' => $ttl,
        ], $request);

        return response()->json([
            'play_token' => $playToken,
            'ttl' => $ttl,
            'qualities' => $available,
            'default_quality' => $resolved,
            'stream_url' => url('/stream/play/' . rawurlencode($playToken)),
        ]);
    }
}
