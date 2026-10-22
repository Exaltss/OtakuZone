<?php
declare(strict_types=1);

use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureLocalAccess;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ForceHttps::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'local.only' => EnsureLocalAccess::class,
            'admin.only' => EnsureAdminAccess::class,
        ]);

        // Route login blueprint bernama 'auth.login'; arahkan guest ke path-nya
        // agar middleware auth tidak melempar RouteNotFoundException (500).
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
