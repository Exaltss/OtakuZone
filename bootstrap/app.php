<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : bootstrap/app.php
 * Fungsi : bootstrap Laravel 11 + Inertia + alias middleware lokal
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §6.1, §7.3
 */
declare(strict_types=1);

use App\Http\Middleware\EnsureLocalAccess;
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
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'local.only' => EnsureLocalAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
