<?php
declare(strict_types=1);

namespace App\Providers;

use App\Services\Animeku\AnimekuClient;
use App\Services\Animeku\CircuitBreaker;
use App\Services\Animeku\ResolverChain;
use App\Services\Animeku\ResponseMapper;
use App\Services\Animeku\SeriesResolver;
use App\Services\Security\AuditWriter;
use App\Services\Security\IpHasher;
use App\Services\Stream\QualityResolver;
use App\Services\Stream\TokenService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ZonaotakuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(ResolverChain::class);
        $this->app->singleton(ResponseMapper::class);
        $this->app->singleton(SeriesResolver::class);
        $this->app->singleton(AnimekuClient::class);
        $this->app->singleton(IpHasher::class);
        $this->app->singleton(AuditWriter::class);
        $this->app->singleton(QualityResolver::class);
        $this->app->singleton(TokenService::class);
    }

    public function boot(): void
    {
        $streamRoutes = base_path('routes/stream.php');
        if (is_file($streamRoutes)) {
            Route::group([], $streamRoutes);
        }

        $apiRoutes = base_path('routes/api.php');
        if (is_file($apiRoutes)) {
            Route::middleware('web')->group($apiRoutes);
        }
    }
}
