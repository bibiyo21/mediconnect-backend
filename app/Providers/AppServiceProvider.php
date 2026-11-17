<?php

namespace App\Providers;

use App\Shared\Contracts\Clock;
use App\Shared\Logging\ScrubbingLogManager;
use App\Shared\Services\SystemClock;
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
    public function boot(): void
    {
        //
    }
}
