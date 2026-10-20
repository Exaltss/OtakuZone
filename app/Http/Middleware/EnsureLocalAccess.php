<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Middleware/EnsureLocalAccess.php
 * Fungsi : mengunci endpoint sign lokal hanya untuk APP_ENV=local dan IP loopback
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.2, §7.3
 */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLocalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();

        if (!app()->environment('local') || !in_array($ip, ['127.0.0.1', '::1'], true)) {
            abort(403, 'Endpoint ini hanya aktif untuk akses lokal.');
        }

        return $next($request);
    }
}
