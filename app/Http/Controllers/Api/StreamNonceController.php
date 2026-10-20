<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/StreamNonceController.php
 * Fungsi : terbitkan SIGN nonce 30 detik bound ke user + session
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §7.3
 */
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiErrors;
use App\Http\Controllers\Controller;
use App\Services\Stream\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StreamNonceController extends Controller
{
    use ApiErrors;

    public function store(Request $request, TokenService $tokens): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->apiError(401, 'unauthenticated', 'Login diperlukan.');
        }

        $sessionId = (string) $request->session()->getId();
        $nonce = $tokens->issueNonce((int) $user->id, $sessionId);

        return response()->json([
            'nonce' => $nonce,
            'ttl' => (int) config('zonaotaku.stream.nonce_ttl', 30),
        ]);
    }
}
