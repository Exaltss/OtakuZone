<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/GuestStreamNonceController.php
 * Fungsi : terbitkan SIGN nonce guest (30 detik, bound ke session, tanpa login)
 * Rujuk  : guest streaming, 2026-10-20
 */
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stream\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuestStreamNonceController extends Controller
{
    public function store(Request $request, TokenService $tokens): JsonResponse
    {
        $sessionId = (string) $request->session()->getId();
        $nonce = $tokens->issueNonce(0, $sessionId);

        return response()->json([
            'nonce' => $nonce,
            'ttl' => (int) config('zonaotaku.stream.nonce_ttl', 30),
        ]);
    }
}
