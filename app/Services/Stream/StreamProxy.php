<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Stream/StreamProxy.php
 * Fungsi : range-aware pass-through MP4, guard SSRF lokal, chunk 2MiB
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.5, §2.6, §2.7, §4.3, §4.10
 */
declare(strict_types=1);

namespace App\Services\Stream;

use App\Models\Episode;
use App\Services\Security\AuditWriter;
use App\Services\Security\IpHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class StreamProxy
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly QualityResolver $qualities,
        private readonly AdmissionControl $admission,
        private readonly AuditWriter $audit,
        private readonly IpHasher $hasher
    ) {
    }

    public function handle(Request $request, string $token): Response
    {
        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();

        $verify = $this->tokens->verifyPlayTokenForProxy($token, $ip, $ua);

        if (($verify['ok'] ?? false) !== true) {
            return $this->error(
                403,
                'stream.denied',
                'token_' . (string) ($verify['reason'] ?? 'invalid'),
                'Token stream tidak valid.',
                null,
                null,
                ['stage' => 'token']
            );
        }

        $payload = $verify['payload'] ?? [];
        $episodeId = (int) ($payload['eid'] ?? 0);
        $quality = (string) ($payload['q'] ?? '');

        $episode = Episode::query()->visible()->find($episodeId);

        if ($episode === null) {
            return $this->error(
                404,
                'stream.denied',
                'episode_not_found',
                'Episode tidak ditemukan.',
                null,
                $episodeId,
                ['stage' => 'episode']
            );
        }

        $qualities = $episode->quality_urls ?? [];

        if (!is_array($qualities) || $qualities === []) {
            return $this->error(
                410,
                'stream.denied',
                'quality_unavailable',
                'Kualitas tidak tersedia.',
                null,
                $episodeId,
                ['stage' => 'quality']
            );
        }

        if (!isset($qualities[$quality]) || !is_string($qualities[$quality]) || $qualities[$quality] === '') {
            return $this->error(
                410,
                'stream.denied',
                'quality_expired',
                'Kualitas expire atau tidak tersedia.',
                null,
                $episodeId,
                ['stage' => 'quality']
            );
        }

        $url = $qualities[$quality];

        try {
            $this->guardStreamUrl($url);
        } catch (RuntimeException $e) {
            return $this->error(
                403,
                'stream.denied',
                'url_blocked',
                'URL stream diblokir oleh guard.',
                null,
                $episodeId,
                ['stage' => 'ssrf', 'guard_error' => $e->getMessage()]
            );
        }

        $uid = (int) ($payload['uid'] ?? 0);

        $guestKey = $uid === 0 ? $this->hasher->hashIpPrefix($ip) : null;

        if (!$this->admission->allow($uid, $guestKey)) {
            return $this->error(
                503,
                'stream.admission_reject',
                'admission_full',
                'Server padat, coba lagi.',
                $uid,
                $episodeId,
                ['stage' => 'admission']
            );
        }

        $range = $request->headers->get('Range');
        $upstream = $this->openUpstream($url, is_string($range) ? $range : null, 0);

        if (($upstream['ok'] ?? false) !== true) {
            $code = (string) ($upstream['code'] ?? 'upstream_failed');
            $action = match ($code) {
                'guard_fail' => 'stream.guard_fail',
                'redirect_blocked' => 'stream.redirect_blocked',
                default => 'stream.denied',
            };

            return $this->error(
                (int) ($upstream['status'] ?? 502),
                $action,
                $code,
                (string) ($upstream['message'] ?? 'Upstream gagal.'),
                $uid,
                $episodeId,
                ['stage' => 'upstream', 'upstream_code' => $code]
            );
        }

        $chunkSize = (int) config('zonaotaku.stream.chunk_size', 2097152);
        $stream = $upstream['stream'];
        $first = (string) $upstream['first'];
        $status = (int) $upstream['status'];

        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => (string) $upstream['content_type'],
            'Cache-Control' => 'private, no-transform',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'identity',
        ];

        if (!empty($upstream['content_length'])) {
            $headers['Content-Length'] = (string) $upstream['content_length'];
        }

        if (!empty($upstream['content_range'])) {
            $headers['Content-Range'] = (string) $upstream['content_range'];
        }

        $callback = function () use ($stream, $first, $chunkSize): void {
            // Bila klien putus (pindah halaman / tutup tab), PHP harus berhenti
            // memompa agar worker segera bebas dan request lain tidak mengantre.
            ignore_user_abort(false);

            echo $first;
            flush();

            while (!$stream->eof()) {
                if (connection_aborted()) {
                    break;
                }

                $chunk = $stream->read($chunkSize);

                if ($chunk === '') {
                    break;
                }

                echo $chunk;
                flush();

                if (connection_aborted()) {
                    break;
                }

                usleep(1000);
                @set_time_limit(60);
            }

            // lepas koneksi upstream sesegera mungkin supaya worker cepat bebas
            if (is_object($stream) && method_exists($stream, 'close')) {
                $stream->close();
            }
        };

        return new StreamedResponse($callback, $status, $headers);
    }

    /**
     * Guard stream URL: hanya http/https, blok private/reserved IP
     */
    private function guardStreamUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('invalid_stream_url');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('stream_scheme_blocked');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw new RuntimeException('stream_private_or_reserved_ip');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function openUpstream(string $url, ?string $range, int $redirectDepth = 0): array
    {
        $chunkSize = (int) config('zonaotaku.stream.chunk_size', 2097152);
        $headers = $this->streamHeaders();

        if ($range !== null && $range !== '') {
            $headers['Range'] = $range;
        }

        try {
            $response = Http::withHeaders($headers)
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ])
                ->timeout((int) config('zonaotaku.animeku.timeout', 20))
                ->get($url);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => 502,
                'code' => 'upstream_connect_failed',
                'message' => 'Koneksi upstream gagal.',
                'exception' => $e->getMessage(),
            ];
        }

        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            if ($redirectDepth >= 2) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'code' => 'redirect_blocked',
                    'message' => 'Redirect terlalu dalam.',
                ];
            }

            $location = (string) $response->header('Location');

            if ($location === '') {
                return [
                    'ok' => false,
                    'status' => 502,
                    'code' => 'redirect_missing',
                    'message' => 'Redirect tanpa Location.',
                ];
            }

            $absolute = $this->absoluteUrl($url, $location);

            try {
                $this->guardStreamUrl($absolute);
            } catch (RuntimeException) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'code' => 'redirect_blocked',
                    'message' => 'Redirect keluar whitelist.',
                ];
            }

            return $this->openUpstream($absolute, $range, $redirectDepth + 1);
        }

        if (!in_array($status, [200, 206], true)) {
            return [
                'ok' => false,
                'status' => match ($status) {
                    404 => 404,
                    410 => 410,
                    403 => 403,
                    default => 502,
                },
                'code' => match ($status) {
                    404 => 'upstream_not_found',
                    410 => 'upstream_gone',
                    403 => 'upstream_forbidden',
                    default => 'upstream_status_' . $status,
                },
                'message' => 'Upstream menolak request.',
            ];
        }

        $contentType = (string) $response->header('Content-Type');
        $contentLength = $response->header('Content-Length');
        $contentRange = $response->header('Content-Range');

        if (!str_starts_with($contentType, 'video/') && $contentType !== 'application/octet-stream') {
            return [
                'ok' => false,
                'status' => 415,
                'code' => 'guard_fail',
                'message' => 'Content-Type upstream bukan video.',
            ];
        }

        if ($contentLength !== null) {
            $length = (int) $contentLength;

            if ($length <= 0 || $length > 5368709120) {
                return [
                    'ok' => false,
                    'status' => 415,
                    'code' => 'guard_fail',
                    'message' => 'Content-Length upstream tidak wajar.',
                ];
            }
        }

        $psr = $response->toPsrResponse();
        $stream = $psr->getBody();
        $first = $stream->read($chunkSize);

        $isStart = $range === null || $range === '' || preg_match('/^bytes\s*=\s*0-/i', $range) === 1;

        if ($first === '') {
            return [
                'ok' => false,
                'status' => 415,
                'code' => 'guard_fail',
                'message' => 'Body upstream kosong.',
            ];
        }

        if ($isStart) {
            $head = substr($first, 0, 64);

            if (!str_contains($head, 'ftyp') && !str_contains($head, 'moov')) {
                return [
                    'ok' => false,
                    'status' => 415,
                    'code' => 'guard_fail',
                    'message' => 'Magic-byte MP4 tidak ditemukan.',
                ];
            }
        }

        return [
            'ok' => true,
            'status' => $status,
            'stream' => $stream,
            'first' => $first,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'content_range' => $contentRange,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function streamHeaders(): array
    {
        $headers = config('zonaotaku.animeku.headers', []);

        if (!is_array($headers)) {
            $headers = [];
        }

        $out = [];

        foreach ($headers as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }

        $out['Referer'] = 'https://pencarinafkah.xyz/';
        $out['Accept'] = '*/*';
        unset($out['Origin']);

        return $out;
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        if ($parts === false || !isset($parts['host'])) {
            return $location;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        $authority = $host . $port;

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $authority . $location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $dir = rtrim(dirname($path), '/');

        return $scheme . '://' . $authority . $dir . '/' . ltrim($location, '/');
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function error(
        int $status,
        string $action,
        string $code,
        string $message,
        ?int $userId,
        ?int $episodeId,
        array $meta = []
    ): Response {
        $this->audit->write($action, $userId, 'Episode', $episodeId, $meta + ['code' => $code]);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
