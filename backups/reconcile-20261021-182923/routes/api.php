<?php
declare(strict_types=1);

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', HealthController::class)->name('api.health');

// Guest maupun user login boleh memberi rating SATU kali (manajemen = admin, di routes/web.php)
Route::post('/api/ratings', [RatingController::class, 'store'])->middleware('throttle:30,1')->name('api.ratings.store');

Route::middleware(['auth', 'throttle:60,1'])->group(function (): void {
    Route::post('/api/progress', [ProgressController::class, 'store'])->name('api.progress.store');
    Route::post('/api/watchlist', [WatchlistController::class, 'store'])->name('api.watchlist.store');
    Route::delete('/api/watchlist/{animeId}', [WatchlistController::class, 'destroy'])->name('api.watchlist.destroy');
});
