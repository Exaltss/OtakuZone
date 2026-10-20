<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Stream/AdmissionControl.php
 * Fungsi : admission control stream global & per-user / per-guest(IP) via Redis zset.
 *          Degradasi aman jika Redis mati.
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
    private const GUEST_PREFIX = 'zo:stream:active:guest:';

    public function __construct(
        private readonly int $maxGlobal = 12,
        private readonly int $maxPerUser = 2,
        private readonly int $ttl = 2100 // 35 menit
    ) {
    }

    public static function make(): self
    {
        return new self(
            (int) config('zonaotaku.stream.max_concurrent', 12),
            (int) config('zonaotaku.stream.per_user_max', 2),
            (int) config('zonaotaku.stream.token_ttl', 2100)
        );
    }

    /**
     * $guestKey = hash prefix IP (diisi controller/proxy bila userId 0),
     * supaya kuota "per user" guest berlaku per rumah/IP, bukan se-dunia.
     */
    public function allow(int $userId, ?string $guestKey = null): bool
    {
        try {
            $this->prune();

            if ((int) Redis::zcard(self::GLOBAL_KEY) >= $this->maxGlobal) {
                return false;
            }

            $scopeKey = $this->scopeKey($userId, $guestKey);

            // prune member kedaluwarsa pada scope ini sebelum menghitung
            Redis::zremrangebyscore($scopeKey, 0, now()->timestamp);

            if ((int) Redis::zcard($scopeKey) >= $this->maxPerUser) {
                return false;
            }

            return true;
        } catch (Throwable) {
            // Redis mati = degradasi terdokumentasi, admission dilewati.
            return true;
        }
    }

    public function register(int $userId, string $tokenHash, ?string $guestKey = null): void
    {
        try {
            $exp = now()->addSeconds($this->ttl)->timestamp;

            Redis::zadd(self::GLOBAL_KEY, $exp, $tokenHash);
            Redis::zadd($this->scopeKey($userId, $guestKey), $exp, $tokenHash);
        } catch (Throwable) {
            // Ignore
        }
    }

    public function release(int $userId, string $tokenHash, ?string $guestKey = null): void
    {
        try {
            Redis::zrem(self::GLOBAL_KEY, $tokenHash);
            Redis::zrem($this->scopeKey($userId, $guestKey), $tokenHash);
        } catch (Throwable) {
            // Ignore
        }
    }

    private function scopeKey(int $userId, ?string $guestKey): string
    {
        if ($userId > 0) {
            return self::USER_PREFIX . $userId;
        }

        return self::GUEST_PREFIX . ($guestKey !== null && $guestKey !== '' ? $guestKey : 'anon');
    }

    private function prune(): void
    {
        Redis::zremrangebyscore(self::GLOBAL_KEY, 0, now()->timestamp);
    }
}
