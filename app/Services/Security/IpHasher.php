<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Security/IpHasher.php
 * Fungsi : hash IP prefix /24 atau /64 dan UA sanitized dengan salt
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §4.8
 */
declare(strict_types=1);

namespace App\Services\Security;

final class IpHasher
{
    public function hashIpPrefix(string $ip): string
    {
        $prefix = $this->prefix($ip);

        return hash('sha256', $prefix . '|' . $this->salt());
    }

    public function hashUa(string $ua): string
    {
        $clean = function_exists('zonaotaku_ua_sanitize')
            ? zonaotaku_ua_sanitize($ua)
            : (string) preg_replace('/\s+/', ' ', trim(str_replace(["\r", "\n"], ' ', $ua)));

        return hash('sha256', $clean . '|' . $this->salt());
    }

    private function prefix(string $ip): string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return ($parts[0] ?? '0') . '.' . ($parts[1] ?? '0') . '.' . ($parts[2] ?? '0') . '.0';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed !== false) {
                $prefixBytes = substr($packed, 0, 8);
                $expanded = inet_ntop($prefixBytes . str_repeat("\0", 8));

                return ($expanded === false ? $ip : $expanded) . '/64';
            }
        }

        return $ip;
    }

    private function salt(): string
    {
        $salt = (string) config('zonaotaku.security.audit_salt', '');

        return $salt !== '' ? $salt : 'zonaotaku-missing-audit-salt';
    }
}
