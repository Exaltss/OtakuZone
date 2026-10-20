<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Middleware/StreamNoCompress.php
 * Fungsi : paksa no-gzip/no-transform untuk route stream
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.3, §10.2
 */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StreamNoCompress
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'private, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Content-Encoding', 'identity');

        return $response;
    }
}
