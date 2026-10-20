<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Providers/ZonaotakuServiceProvider.php
 * Fungsi : load routes/stream.php secara terisolasi
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §6.1, §7.3
 */
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ZonaotakuServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $streamRoutes = base_path('routes/stream.php');

        if (is_file($streamRoutes)) {
            Route::group([], $streamRoutes);
        }
    }
}
