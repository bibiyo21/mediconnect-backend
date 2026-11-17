<?php

namespace App\Modules\Authentication\Providers;

use App\Modules\Authentication\Http\Middleware\EnsureMfaSatisfied;
use App\Modules\Authentication\Http\Middleware\EnsureSignInIsValid;
use App\Modules\Authentication\Http\Middleware\RequireStepUp;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

final class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Google2FA::class, fn () => new Google2FA);
    }

    public function boot(Router $router): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $router->aliasMiddleware('signin.valid', EnsureSignInIsValid::class);
        $router->aliasMiddleware('mfa.satisfied', EnsureMfaSatisfied::class);
        $router->aliasMiddleware('step-up', RequireStepUp::class);

        $this->rateLimits();

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }

    /** api-specification.md §1.7. Keys are hashed so identifiers do not sit in Redis in clear. */
    private function rateLimits(): void
    {
        RateLimiter::for('auth-strict', function (Request $request) {
            $identifier = (string) ($request->input('identifier') ?? $request->input('email') ?? $request->input('mobile') ?? $request->input('mfa_challenge_id') ?? '');

            return [
                Limit::perMinute(5)->by('auth-id:'.Fingerprint::of(mb_strtolower(trim($identifier)))),
                Limit::perMinute(20)->by('auth-ip:'.Fingerprint::ip($request->ip())),
            ];
        });

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by('otp-ip:'.Fingerprint::ip($request->ip())));

        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute(30)->by('refresh-ip:'.Fingerprint::ip($request->ip())));
    }
}
