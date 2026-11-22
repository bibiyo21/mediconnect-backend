<?php

namespace App\Providers;

use App\Shared\Contracts\Clock;
use App\Shared\Contracts\MalwareScanner;
use App\Shared\Contracts\SearchEngine;
use App\Shared\Http\Middleware\EnsureIdempotency;
use App\Shared\Logging\ScrubbingLogManager;
use App\Shared\Malware\ClamdScanner;
use App\Shared\OpenApi\DocumentsErrorEnvelope;
use App\Shared\OpenApi\MarksAuthenticatedOperations;
use App\Shared\Search\TypesenseEngine;
use App\Shared\Services\SystemClock;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Typesense\Client as TypesenseClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(SearchEngine::class, function () {
            $c = (array) config('search.typesense');
            $node = ['host' => (string) $c['host'], 'port' => (string) $c['port'], 'protocol' => (string) $c['protocol']];

            return new TypesenseEngine(
                new TypesenseClient(['api_key' => (string) $c['api_key'], 'nodes' => [$node], 'connection_timeout_seconds' => (float) $c['timeout_seconds'], 'num_retries' => 1]),
                $node['protocol'].'://'.$node['host'].':'.$node['port'],
                (string) $c['api_key'],
            );
        });
        $this->app->singleton(MalwareScanner::class, fn () => new ClamdScanner(
            (string) config('mediconnect.malware_scanner.host'),
            (int) config('mediconnect.malware_scanner.port'),
            (float) config('mediconnect.malware_scanner.timeout_seconds'),
        ));
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
        Scramble::configure()->withOperationTransformers(MarksAuthenticatedOperations::class)->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->servers = [new Server('/api/v1')];
            $openApi->components->addSecurityScheme('bearer', SecurityScheme::http('bearer'));
            $openApi->components->addSecurityScheme('session', SecurityScheme::apiKey('cookie', 'mediconnect-session'));
            (new DocumentsErrorEnvelope)($openApi);
        });
    }
}
