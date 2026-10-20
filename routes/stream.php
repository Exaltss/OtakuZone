<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : routes/stream.php
 * Fungsi : route nonce/sign auth, sign lokal-only, dan proxy play terisolasi
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §7.3
 */
declare(strict_types=1);

use App\Http\Controllers\Api\GuestStreamNonceController;
use App\Http\Controllers\Api\GuestStreamSignController;
use App\Http\Controllers\Api\LocalStreamSignController;
use App\Http\Controllers\Api\StreamNonceController;
use App\Http\Controllers\Api\StreamProxyController;
use App\Http\Controllers\Api\StreamSignController;
use App\Http\Middleware\StreamNoCompress;
use Illuminate\Support\Facades\Route;

$signThrottle = (int) config('zonaotaku.throttle.sign', 20);

Route::middleware(['web', 'auth', 'throttle:' . $signThrottle . ',1'])->group(function (): void {
    Route::post('/api/stream/nonce', [StreamNonceController::class, 'store'])
        ->name('stream.nonce');

    Route::post('/api/stream/sign', [StreamSignController::class, 'store'])
        ->name('stream.sign');
});

Route::post('/api/local/stream/sign', [LocalStreamSignController::class, 'store'])
    ->middleware(['web', 'local.only', 'throttle:120,1'])
    ->name('stream.local.sign');

$guestThrottle = (int) config('zonaotaku.throttle.guest_sign', 30);

Route::middleware(['web', 'throttle:' . $guestThrottle . ',1'])->group(function (): void {
    Route::post('/api/guest/stream/nonce', [GuestStreamNonceController::class, 'store'])
        ->name('stream.guest.nonce');

    Route::post('/api/guest/stream/sign', [GuestStreamSignController::class, 'store'])
        ->name('stream.guest.sign');
});

Route::get('/stream/play/{token}', [StreamProxyController::class, 'play'])
    ->middleware([StreamNoCompress::class])
    ->name('stream.play');
