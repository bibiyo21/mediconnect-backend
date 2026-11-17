<?php

namespace App\Providers;

use App\Shared\Contracts\Clock;
use App\Shared\Http\Middleware\EnsureIdempotency;
use App\Shared\Logging\ScrubbingLogManager;
use App\Shared\Services\SystemClock;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
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

        // The committed OpenAPI contract must not depend on APP_URL or any .env value.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->servers = [new Server('/api/v1')];
            $openApi->components->addSecurityScheme('bearer', SecurityScheme::http('bearer'));
            $openApi->components->addSecurityScheme('session', SecurityScheme::apiKey('cookie', 'mediconnect-session'));
        });
    }
}
