<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Animeku/AnimekuClient.php
 * Fungsi : SATU pintu outbound ke Animeku: header wajib, resolver chain, breaker, throttle, SSRF guard
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2, §5.3, §13.3
 */
declare(strict_types=1);

namespace App\Services\Animeku;

use App\Models\Setting;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\Http;

final class AnimekuClient
{
    public function __construct(
        private readonly ResolverChain $resolver,
        private readonly ResponseMapper $mapper,
        private readonly CircuitBreaker $breaker
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function latest(int $page = 1, int $count = 20): array
    {
        return $this->request('videos', [
            'page' => $page,
            'count' => $count,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function popular(int $page = 1, int $count = 20): array
    {
        return $this->request('popular', [
            'page' => $page,
            'count' => $count,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function genre(string $genre, int $count = 20): array
    {
        return $this->request('genre', [
            'genre' => $genre,
            'count' => $count,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(string $id): array
    {
        return $this->request('detail', [
            'id' => $id,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function postDetail(string $id): array
    {
        return $this->request('post_detail', [
            'id' => $id,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function nextPrevious(string $id, int $catId): array
    {
        return $this->request('next_previous', [
            'id' => $id,
            'cat_id' => $catId,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function testConnection(): array
    {
        $results = [];

        foreach ($this->resolver->urls(fresh: true) as $url) {
            $startedAt = microtime(true);

            try {
                $this->requestUrl($url, 'videos', ['page' => 1, 'count' => 1], false);
                $this->breaker->success($url);

                $results[] = [
                    'base_url' => $url,
                    'ok' => true,
                    'ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            } catch (Throwable $e) {
                $this->breaker->failure($url);

                $results[] = [
                    'base_url' => $url,
                    'ok' => false,
                    'ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function request(string $templateKey, array $params): array
    {
        $urls = $this->resolver->urls();

        if ($urls === []) {
            throw new RuntimeException('no_upstream_url');
        }

        $lastError = null;

        foreach ($urls as $url) {
            if (!$this->breaker->allow($url)) {
                continue;
            }

            try {
                $data = $this->requestUrl($url, $templateKey, $params, true);
                $this->breaker->success($url);

                return $data;
            } catch (Throwable $e) {
                $lastError = $e;
                $this->breaker->failure($url);
            }
        }

        throw $lastError ?? new RuntimeException('all_upstreams_failed_or_open');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function requestUrl(string $baseUrl, string $templateKey, array $params, bool $throttle): array
    {
        $template = $this->endpointTemplate($templateKey);
        $url = $this->buildUrl($baseUrl, $template, $params);

        $this->guardUrl($url);

        $lastError = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($throttle) {
                $this->throttle();
            }

            try {
                $response = Http::withHeaders($this->headers())
                    ->timeout((int) config('zonaotaku.animeku.timeout', 20))
                    ->get($url);

                if ($response->successful()) {
                    $data = $response->json();

                    if (!is_array($data)) {
                        throw new RuntimeException('invalid_json_response');
                    }

                    return $data;
                }

                $lastError = new RuntimeException("upstream_status:{$response->status()}");
            } catch (Throwable $e) {
                $lastError = $e;
            }

            if ($attempt < 2) {
                sleep($attempt === 0 ? 2 : 4);
            }
        }

        throw $lastError ?? new RuntimeException('upstream_request_failed');
    }

    private function endpointTemplate(string $key): string
    {
        $templates = $this->jsonSetting('animeku.endpoint_templates_json') ?? config('zonaotaku.animeku.endpoint_templates', []);

        if (!is_array($templates) || !isset($templates[$key]) || !is_string($templates[$key])) {
            throw new RuntimeException("missing_endpoint_template:{$key}");
        }

        return $templates[$key];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function buildUrl(string $baseUrl, string $template, array $params): string
    {
        $base = rtrim($baseUrl, '/');
        $path = ltrim($template, '/');
        $url = $base . '/' . $path;

        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', rawurlencode((string) $value), $url);
        }

        $apiKey = $this->value('animeku.api_key', config('zonaotaku.animeku.api_key'));

        if (!is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('missing_api_key');
        }

        $url = str_replace('{api_key}', rawurlencode($apiKey), $url);

        if (str_contains($url, '{')) {
            throw new RuntimeException("unresolved_placeholder:{$url}");
        }

        return $url;
    }

    private function guardUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('invalid_upstream_url');
        }

        if ($parts['scheme'] !== 'https') {
            throw new RuntimeException('upstream_scheme_must_https');
        }

        $host = strtolower((string) $parts['host']);
        $whitelist = $this->jsonSetting('animeku.upstream_whitelist_json') ?? config('zonaotaku.animeku.upstream_whitelist', []);

        if (!is_array($whitelist) || $whitelist === []) {
            throw new RuntimeException('empty_upstream_whitelist');
        }

        $allowed = false;

        foreach ($whitelist as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));

            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new RuntimeException('upstream_host_not_whitelisted');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw new RuntimeException('upstream_private_or_reserved_ip');
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        $headers = $this->jsonSetting('animeku.headers_json') ?? config('zonaotaku.animeku.headers', []);

        if (!is_array($headers)) {
            $headers = [];
        }

        $out = [];

        foreach ($headers as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    private function throttle(): void
    {
        $ms = (int) config('zonaotaku.animeku.throttle_ms', 400);
        $jitter = random_int(0, (int) config('zonaotaku.animeku.jitter_ms', 300));

        usleep(($ms + $jitter) * 1000);
    }

    private function value(string $key, mixed $default): mixed
    {
        $value = Setting::get($key);

        return $value === null ? $default : $value;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function jsonSetting(string $key): ?array
    {
        $value = Setting::get($key);

        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
