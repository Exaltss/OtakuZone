<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Concerns/ApiErrors.php
 * Fungsi : error contract seragam §7.5
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §7.5
 */
declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait ApiErrors
{
    protected function apiError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
