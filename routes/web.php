<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : routes/web.php
 * Fungsi : route web katalog, detail anime, dan watch page lokal
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §6.1, §7.2
 */
declare(strict_types=1);

use App\Http\Controllers\Web\AnimeController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\WatchController;
use Illuminate\Support\Facades\Route;

Route::get('/', CatalogController::class)
    ->name('catalog.index');

Route::get('/anime/{slug}', [AnimeController::class, 'show'])
    ->name('anime.show');

Route::get('/watch/{episode}', [WatchController::class, 'show'])
    ->name('watch.show');
