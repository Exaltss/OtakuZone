<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Security/LogSanitizer.php
 * Fungsi : processor Monolog untuk remove/redact key sensitif
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.8
 */
declare(strict_types=1);

namespace App\Services\Security;

final class LogSanitizer
{
    /**
     * Key yang nilainya dibuang total dari log.
     *
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
     * Key yang nilainya diganti [REDACTED].
     *
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

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function __invoke(array $record): array
    {
        foreach (['context', 'extra'] as $bucket) {
            if (isset($record[$bucket]) && is_array($record[$bucket])) {
                $record[$bucket] = $this->sanitize($record[$bucket]);
            }
        }

        return $record;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_array($value)) {
                $data[$key] = $this->sanitize($value);
                continue;
            }

            if (is_string($key)) {
                $lower = strtolower($key);

                if (in_array($lower, self::REMOVE_KEYS, true)) {
                    unset($data[$key]);
                    continue;
                }

                if (in_array($lower, self::REDACT_KEYS, true)) {
                    $data[$key] = '[REDACTED]';
                    continue;
                }
            }
        }

        return $data;
    }
}
