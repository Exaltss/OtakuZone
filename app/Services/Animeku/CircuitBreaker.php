<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Animeku/CircuitBreaker.php
 * Fungsi : breaker per base_url upstream: closed/open/half-open
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.8, §5.5
 */
declare(strict_types=1);

namespace App\Services\Animeku;

use Illuminate\Support\Facades\Cache;

final class CircuitBreaker
{
    private const PREFIX = 'zo:cb:';

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 600
    ) {
    }

    public function allow(string $baseUrl): bool
    {
        $state = $this->state($baseUrl);

        if ($state === null) {
            return true;
        }

        if (($state['status'] ?? 'closed') === 'open') {
            if ((int) ($state['open_until'] ?? 0) > now()->timestamp) {
                return false;
            }

            $this->transitionHalfOpen($baseUrl);

            return true;
        }

        return true;
    }

    public function success(string $baseUrl): void
    {
        Cache::forget($this->key($baseUrl));
    }

    public function failure(string $baseUrl): void
    {
        $state = $this->state($baseUrl) ?? [
            'failures' => 0,
            'status' => 'closed',
            'open_until' => 0,
        ];

        $failures = (int) ($state['failures'] ?? 0) + 1;

        if ($failures >= $this->failureThreshold) {
            Cache::put(
                $this->key($baseUrl),
                [
                    'failures' => $failures,
                    'status' => 'open',
                    'open_until' => now()->addSeconds($this->openSeconds)->timestamp,
                ],
                now()->addSeconds($this->openSeconds + 3600)
            );

            return;
        }

        Cache::put(
            $this->key($baseUrl),
            [
                'failures' => $failures,
                'status' => 'closed',
                'open_until' => 0,
            ],
            now()->addHour()
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $baseUrl): array
    {
        $state = $this->state($baseUrl) ?? [
            'failures' => 0,
            'status' => 'closed',
            'open_until' => 0,
        ];

        return [
            'base_url' => $baseUrl,
            'status' => (string) ($state['status'] ?? 'closed'),
            'failures' => (int) ($state['failures'] ?? 0),
            'open_until' => (int) ($state['open_until'] ?? 0),
            'allowed' => $this->allow($baseUrl),
        ];
    }

    private function transitionHalfOpen(string $baseUrl): void
    {
        Cache::put(
            $this->key($baseUrl),
            [
                'failures' => $this->failureThreshold,
                'status' => 'half_open',
                'open_until' => 0,
            ],
            now()->addMinutes(10)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function state(string $baseUrl): ?array
    {
        $state = Cache::get($this->key($baseUrl));

        return is_array($state) ? $state : null;
    }

    private function key(string $baseUrl): string
    {
        return self::PREFIX . hash('sha256', $baseUrl);
    }
}
