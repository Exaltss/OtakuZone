<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        $localHost = in_array($request->getHost(), ['127.0.0.1', 'localhost', '::1'], true);

        if (app()->environment('production') && !$request->secure() && !$localHost) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        if (!str_starts_with($request->path(), 'stream/play/')) {
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        }

        return $response;
    }
}
