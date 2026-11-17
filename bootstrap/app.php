<?php

use App\Shared\Exceptions\ApiExceptionRenderer;
use App\Shared\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so every log line and error response carries the request ID.
        $middleware->prepend(AssignRequestId::class);

        // Sanctum: cookie sessions for first-party web apps on the stateful domains (D-04).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report to Sentry when SENTRY_LARAVEL_DSN is set (no-op otherwise); events pass SentryScrubber.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::shouldRender($request),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiExceptionRenderer::shouldRender($request)
                ? ApiExceptionRenderer::render($e)
                : null,
        );
    })->create();
