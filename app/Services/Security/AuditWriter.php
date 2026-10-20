<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Security/AuditWriter.php
 * Fungsi : penulis audit log dengan IP/UA hash dan meta sanitized
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.8, §4.11
 */
declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AuditLog;
use Illuminate\Http\Request;

final class AuditWriter
{
    /**
     * @var array<int,string>
     */
    private const REMOVE_KEYS = [
        'url',
        'quality_urls',
        'stream_url',
        'play_url',
        'embed_url',
        'iframe',
        'src',
    ];

    /**
     * @var array<int,string>
     */
    private const REDACT_KEYS = [
        'token',
        'api_key',
        'apikey',
        'password',
        'secret',
        'nonce',
        'authorization',
        'cookie',
        'session',
    ];

    public function __construct(
        private readonly IpHasher $hasher
    ) {
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function write(
        string $action,
        ?int $userId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        ?Request $request = null
    ): AuditLog {
        $ipHash = null;
        $uaHash = null;

        if ($request !== null) {
            $ipHash = $this->hasher->hashIpPrefix((string) $request->ip());
            $uaHash = $this->hasher->hashUa((string) $request->userAgent());
        }

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_hash' => $ipHash,
            'ua_hash' => $uaHash,
            'meta' => $this->sanitizeMeta($meta),
        ]);
    }

    /**
     * @param array<mixed> $meta
     * @return array<mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        foreach (array_keys($meta) as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }

            $value = $meta[$key];

            if (is_array($value)) {
                $meta[$key] = $this->sanitizeMeta($value);
                continue;
            }

            if (is_string($key)) {
                $lower = strtolower($key);

                if (in_array($lower, self::REMOVE_KEYS, true)) {
                    unset($meta[$key]);
                    continue;
                }

                if (in_array($lower, self::REDACT_KEYS, true)) {
                    $meta[$key] = '[REDACTED]';
                    continue;
                }
            }

            if (is_string($value) && strlen($value) > 2048) {
                $meta[$key] = substr($value, 0, 2048) . '...[TRUNCATED]';
            }
        }

        return $meta;
    }
}
