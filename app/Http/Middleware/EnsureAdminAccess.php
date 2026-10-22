<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('auth.login');
        }

        if ($user->isBanned() || !$user->isAdmin()) {
            abort(403, 'Akses khusus Administrator Zonaotaku.');
        }

        return $next($request);
    }
}
