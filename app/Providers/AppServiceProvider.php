<?php

namespace App\Providers;

use App\Shared\Contracts\Clock;
use App\Shared\Http\Middleware\EnsureIdempotency;
use App\Shared\Logging\ScrubbingLogManager;
use App\Shared\Services\SystemClock;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton('log', fn ($app) => new ScrubbingLogManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Router $router): void
    {
        // Critical writes opt in per route: ->middleware('idempotent').
        $router->aliasMiddleware('idempotent', EnsureIdempotency::class);
    }
}
