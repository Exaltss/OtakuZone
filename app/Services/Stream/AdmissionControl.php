<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Stream/AdmissionControl.php
 * Fungsi : admission control stream global & per user via Redis zset. Degradasi aman jika Redis mati.
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.10
 */
declare(strict_types=1);

namespace App\Services\Stream;

use Illuminate\Support\Facades\Redis;
use Throwable;

final class AdmissionControl
{
    private const GLOBAL_KEY = 'zo:stream:active:global';
    private const USER_PREFIX = 'zo:stream:active:user:';

    public function __construct(
        private readonly int $maxGlobal = 12,
        private readonly int $maxPerUser = 2,
        private readonly int $ttl = 2100 // 35 menit
    ) {}

    public static function make(): self
    {
        return new self(
            (int) config('zonaotaku.stream.max_concurrent', 12),
            (int) config('zonaotaku.stream.per_user_max', 2),
            (int) config('zonaotaku.stream.token_ttl', 2100)
        );
    }

    public function allow(int $userId): bool
    {
        try {
            $this->prune();
            
            $globalCount = (int) Redis::zcard(self::GLOBAL_KEY);
            if ($globalCount >= $this->maxGlobal) {
                return false;
            }

            $userKey = self::USER_PREFIX . $userId;
            $userCount = (int) Redis::zcard($userKey);
            if ($userCount >= $this->maxPerUser) {
                return false;
            }

            return true;
        } catch (Throwable) {
            // Redis mati = degradasi terdokumentasi, admission dilewati.
            return true;
        }
    }

    public function register(int $userId, string $tokenHash): void
    {
        try {
            $exp = now()->addSeconds($this->ttl)->timestamp;
            Redis::zadd(self::GLOBAL_KEY, $exp, $tokenHash);
            Redis::zadd(self::USER_PREFIX . $userId, $exp, $tokenHash);
        } catch (Throwable) {
            // Ignore
        }
    }

    public function release(int $userId, string $tokenHash): void
    {
        try {
            Redis::zrem(self::GLOBAL_KEY, $tokenHash);
            Redis::zrem(self::USER_PREFIX . $userId, $tokenHash);
        } catch (Throwable) {
            // Ignore
        }
    }

    private function prune(): void
    {
        $now = now()->timestamp;
        Redis::zremrangebyscore(self::GLOBAL_KEY, 0, $now);
        // Prune user keys secara lazy saat check, atau bisa dijadwalkan.
    }
}
