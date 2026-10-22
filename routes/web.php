<?php
declare(strict_types=1);

use App\Http\Controllers\Web\Admin\AdminDashboardController;
use App\Http\Controllers\Web\AnimeController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\BrowseController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\LibraryController;
use App\Http\Controllers\Web\OgController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\WatchController;
use Illuminate\Support\Facades\Route;

// Rekonsiliasi: halaman genre + pencarian cepat + manajemen rating (admin)

// Route Inti Katalog, Detail Anime, dan Watch Episode (TIDAK DIUBAH)
Route::get('/', CatalogController::class)->name('catalog.index');
Route::get('/anime/{slug}', [AnimeController::class, 'show'])->name('anime.show');
Route::get('/watch/{episode}', [WatchController::class, 'show'])->name('watch.show');

// Route Tambahan Blueprint V2 Final
Route::get('/browse', [BrowseController::class, 'index'])->name('browse.index');
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/og/{slug}', [OgController::class, 'show'])->name('og.show');

// Autentikasi & Library User
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('auth.login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('auth.login.post');
Route::post('/register', [LoginController::class, 'register'])->middleware('throttle:5,1')->name('auth.register.post');
Route::post('/logout', [LoginController::class, 'logout'])->name('auth.logout');

Route::get('/library', [LibraryController::class, 'index'])->middleware('auth')->name('library.index');

// Admin CMS Blueprint §8
Route::middleware(['auth', 'admin.only'])->prefix('admin')->group(function (): void {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::post('/source-config', [AdminDashboardController::class, 'updateSourceConfig'])->name('admin.source.update');
    Route::post('/source-test', [AdminDashboardController::class, 'testUpstream'])->name('admin.source.test');
    Route::post('/episodes/move', [AdminDashboardController::class, 'moveEpisode'])->name('admin.episodes.move');
    Route::post('/anime/merge', [AdminDashboardController::class, 'mergeAnime'])->name('admin.anime.merge');
    Route::post('/groups', [AdminDashboardController::class, 'storeGroup'])->name('admin.groups.store');
    Route::post('/filter-rules', [AdminDashboardController::class, 'storeFilterRule'])->name('admin.rules.store');
    Route::post('/users', [AdminDashboardController::class, 'storeUser'])->name('admin.users.store');
    Route::post('/users/{userId}/toggle-ban', [AdminDashboardController::class, 'toggleBanUser'])->name('admin.users.ban');
    Route::post('/maintenance', [AdminDashboardController::class, 'runMaintenance'])->name('admin.maintenance');
});

Route::get('/genres', \App\Http\Controllers\Web\GenreController::class)->name('genres.index');

Route::get('/api/quick-search', [\App\Http\Controllers\Web\SearchController::class, 'quick'])
    ->middleware('throttle:60,1')
    ->name('api.quick-search');

Route::middleware(['auth', 'admin.only'])->group(function (): void {
    Route::put('/api/ratings/{ratingId}', [\App\Http\Controllers\Api\RatingController::class, 'update'])
        ->name('api.ratings.update');
    Route::delete('/api/ratings/{ratingId}', [\App\Http\Controllers\Api\RatingController::class, 'destroy'])
        ->name('api.ratings.destroy');
});
