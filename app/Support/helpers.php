<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Support/helpers.php
 * Fungsi : canonical JSON, base64url, UA sanitizer
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §4.8
 */
declare(strict_types=1);

if (!function_exists('zonaotaku_canonicalize')) {
    function zonaotaku_canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = zonaotaku_canonicalize($item);
        }

        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}

if (!function_exists('zonaotaku_canonical_json')) {
    function zonaotaku_canonical_json(mixed $value): string
    {
        return json_encode(
            zonaotaku_canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }
}

if (!function_exists('zonaotaku_b64url_encode')) {
    function zonaotaku_b64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('zonaotaku_b64url_decode')) {
    function zonaotaku_b64url_decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}

if (!function_exists('zonaotaku_ua_sanitize')) {
    function zonaotaku_ua_sanitize(?string $ua): string
    {
        $ua = (string) $ua;
        $ua = (string) preg_replace('/[\r\n]+/', ' ', $ua);
        $ua = (string) preg_replace('/\s+/', ' ', $ua);
        $ua = trim($ua);

        if (strlen($ua) > 256) {
            $ua = substr($ua, 0, 256);
        }

        return $ua;
    }
}

if (!function_exists('zonaotaku_poster_url')) {
    function zonaotaku_poster_url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return url('/storage/' . ltrim($path, '/'));
    }
}
