<?php

namespace App\Modules\Authentication\Providers;

use App\Modules\Authentication\Console\AnonymizeDeletedAccounts;
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

        if ($this->app->runningInConsole()) {
            $this->commands([AnonymizeDeletedAccounts::class]);
        }

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }

    /** api-specification.md §1.7. Keys are hashed so identifiers do not sit in Redis in clear. */
    private function rateLimits(): void
    {
        RateLimiter::for('auth-strict', function (Request $request) {
            $identifier = (string) ($request->input('identifier') ?? $request->input('email') ?? $request->input('mobile') ?? $request->input('mfa_challenge_id') ?? '');

            // Requests without an identifier (step-up, verify-my-contact) are limited per
            // signed-in user, or per IP, never in one bucket shared by everybody.
            $subject = match (true) {
                trim($identifier) !== '' => 'id:'.Fingerprint::of(mb_strtolower(trim($identifier))),
                $request->user('sanctum') !== null => 'user:'.$request->user('sanctum')->getAuthIdentifier(),
                default => 'ip:'.Fingerprint::ip($request->ip()),
            };

            return [
                Limit::perMinute(5)->by('auth-'.$subject),
                Limit::perMinute(20)->by('auth-ip:'.Fingerprint::ip($request->ip())),
            ];
        });

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by('otp-ip:'.Fingerprint::ip($request->ip())));

        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute(30)->by('refresh-ip:'.Fingerprint::ip($request->ip())));
    }
}
