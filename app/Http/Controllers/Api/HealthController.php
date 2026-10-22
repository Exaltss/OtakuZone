<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }

        $redisOk = false;
        try {
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                Redis::ping();
                $redisOk = true;
            }
        } catch (Throwable) {
            $redisOk = false;
        }

        $freeBytes = @disk_free_space(base_path());
        $diskMb = is_numeric($freeBytes) ? (int) round(((float) $freeBytes) / 1048576) : 0;

        return response()->json([
            'status' => $dbOk ? 'ok' : 'degraded',
            'db' => $dbOk,
            'redis' => $redisOk,
            'disk_mb' => $diskMb,
            'ts' => now()->timestamp,
        ]);
    }
}
