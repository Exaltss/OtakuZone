<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Stream/TokenService.php
 * Fungsi : SIGN nonce + PLAY token HMAC/encrypted/bound, termasuk verifikasi proxy tanpa session
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2
 */
declare(strict_types=1);

namespace App\Services\Stream;

use App\Services\Security\IpHasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class TokenService
{
    private const NONCE_PREFIX = 'zo:nonce:';
    private const USED_PREFIX = 'zo:nonce_used:';

    public function __construct(
        private readonly IpHasher $hasher
    ) {
    }

    public function issueNonce(int $userId, string $sessionId, ?int $ttl = null): string
    {
        $ttl ??= (int) config('zonaotaku.stream.nonce_ttl', 30);

        $payload = [
            'uid' => $userId,
            'sid' => hash('sha256', $sessionId),
            'exp' => now()->addSeconds($ttl)->timestamp,
            'nce' => bin2hex(random_bytes(16)),
        ];

        Cache::put(
            self::NONCE_PREFIX . hash('sha256', $payload['nce']),
            $payload,
            now()->addSeconds($ttl + 5)
        );

        return zonaotaku_b64url_encode(
            Crypt::encryptString(zonaotaku_canonical_json($payload))
        );
    }

    public function consumeNonce(string $encoded, int $userId, string $sessionId): bool
    {
        try {
            $json = Crypt::decryptString(zonaotaku_b64url_decode($encoded));
        } catch (Throwable) {
            return false;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return false;
        }

        $nonce = (string) ($payload['nce'] ?? '');

        if ($nonce === '') {
            return false;
        }

        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return false;
        }

        if ((int) ($payload['uid'] ?? 0) !== $userId) {
            return false;
        }

        if ((string) ($payload['sid'] ?? '') !== hash('sha256', $sessionId)) {
            return false;
        }

        $hash = hash('sha256', $nonce);
        $usedKey = self::USED_PREFIX . $hash;
        $ttl = (int) config('zonaotaku.stream.nonce_ttl', 30);

        if (!Cache::add($usedKey, 1, now()->addSeconds($ttl + 5))) {
            return false;
        }

        Cache::forget(self::NONCE_PREFIX . $hash);

        return true;
    }

    public function issuePlayToken(
        int $episodeId,
        string $quality,
        int $userId,
        string $ip,
        string $ua,
        ?int $ttl = null
    ): string {
        $ttl ??= (int) config('zonaotaku.stream.token_ttl', 2100);

        $payload = [
            'eid' => $episodeId,
            'q' => $quality,
            'uid' => $userId,
            'iph' => $this->hasher->hashIpPrefix($ip),
            'uah' => $this->hasher->hashUa($ua),
            'exp' => now()->addSeconds($ttl)->timestamp,
            'nce' => bin2hex(random_bytes(16)),
        ];

        $mac = $this->mac($payload);

        return zonaotaku_b64url_encode(
            Crypt::encryptString(zonaotaku_canonical_json([
                'p' => $payload,
                'm' => $mac,
            ]))
        );
    }

    /**
     * @return array{ok:bool, reason?:string, payload?:array<string,mixed>}
     */
    public function verifyPlayToken(string $token, int $userId, string $ip, string $ua): array
    {
        $base = $this->verifyPlayTokenForProxy($token, $ip, $ua);

        if (($base['ok'] ?? false) !== true) {
            return $base;
        }

        $payload = $base['payload'] ?? [];

        if ((int) ($payload['uid'] ?? 0) !== $userId) {
            return ['ok' => false, 'reason' => 'uid'];
        }

        return $base;
    }

    /**
     * @return array{ok:bool, reason?:string, payload?:array<string,mixed>}
     */
    public function verifyPlayTokenForProxy(string $token, string $ip, string $ua): array
    {
        try {
            $json = Crypt::decryptString(zonaotaku_b64url_decode($token));
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'decrypt'];
        }

        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['p'], $data['m']) || !is_array($data['p'])) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $payload = $data['p'];

        if (!hash_equals($this->mac($payload), (string) $data['m'])) {
            return ['ok' => false, 'reason' => 'mac'];
        }

        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        if ((bool) config('zonaotaku.stream.bound_ip', true)) {
            if ((string) ($payload['iph'] ?? '') !== $this->hasher->hashIpPrefix($ip)) {
                return ['ok' => false, 'reason' => 'ip'];
            }
        }

        if ((bool) config('zonaotaku.stream.bound_ua', true)) {
            if ((string) ($payload['uah'] ?? '') !== $this->hasher->hashUa($ua)) {
                return ['ok' => false, 'reason' => 'ua'];
            }
        }

        return [
            'ok' => true,
            'payload' => [
                'eid' => (int) ($payload['eid'] ?? 0),
                'q' => (string) ($payload['q'] ?? ''),
                'uid' => (int) ($payload['uid'] ?? 0),
                'nce' => (string) ($payload['nce'] ?? ''),
                'exp' => (int) ($payload['exp'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function mac(array $payload): string
    {
        return hash_hmac(
            'sha256',
            zonaotaku_canonical_json($payload),
            (string) config('zonaotaku.stream.secret', '')
        );
    }
}
