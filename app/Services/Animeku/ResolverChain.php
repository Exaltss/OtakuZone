<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Animeku/ResolverChain.php
 * Fungsi : rantai base_url upstream: primary -> fallback_1 -> fallback_2
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.1, §2.8, §5.3
 */
declare(strict_types=1);

namespace App\Services\Animeku;

use App\Models\Setting;

final class ResolverChain
{
    /**
     * @var array<int,string>|null
     */
    private ?array $urlsCache = null;

    private ?int $urlsCacheAt = null;

    public function __construct(
        private readonly CircuitBreaker $breaker
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function urls(bool $fresh = false): array
    {
        if (
            !$fresh
            && $this->urlsCache !== null
            && $this->urlsCacheAt !== null
            && now()->timestamp - $this->urlsCacheAt < 60
        ) {
            return $this->urlsCache;
        }

        $candidates = [
            $this->value('animeku.base_url_primary', config('zonaotaku.animeku.base_url_primary')),
            $this->value('animeku.base_url_fallback_1', config('zonaotaku.animeku.base_url_fallback_1')),
            $this->value('animeku.base_url_fallback_2', config('zonaotaku.animeku.base_url_fallback_2')),
        ];

        $urls = [];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $candidate = trim($candidate);

            if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
                continue;
            }

            $scheme = parse_url($candidate, PHP_URL_SCHEME);

            if ($scheme !== 'https') {
                continue;
            }

            $urls[] = $candidate;
        }

        $this->urlsCache = array_values(array_unique($urls));
        $this->urlsCacheAt = now()->timestamp;

        return $this->urlsCache;
    }

    public function allowedUrl(): ?string
    {
        foreach ($this->urls() as $url) {
            if ($this->breaker->allow($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function statuses(): array
    {
        $out = [];

        foreach ($this->urls() as $url) {
            $out[] = $this->breaker->status($url);
        }

        return $out;
    }

    private function value(string $key, mixed $default): mixed
    {
        $value = Setting::get($key);

        return $value === null ? $default : $value;
    }
}
