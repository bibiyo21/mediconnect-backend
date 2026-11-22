<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Realtime\RealtimeConfigGuard;
use App\Modules\Notifications\Services\OneTimeMessageSender;
use App\Modules\Notifications\Sms\DisabledSmsGateway;
use App\Modules\Notifications\Sms\MailPreviewSmsGateway;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SendsOneTimeMessages::class, OneTimeMessageSender::class);

        $this->app->singleton(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'mail-preview' => new MailPreviewSmsGateway,
            default => new DisabledSmsGateway,
        });
    }

    public function boot(): void
    {
        // Channel subscriptions: 60 a minute per user (a client joins a handful of channels at connect).
        RateLimiter::for('broadcast-auth', fn (Request $request) => Limit::perMinute(60)->by('broadcast-auth:'.($request->user()?->getAuthIdentifier() ?? Fingerprint::ip($request->ip()))));

        RealtimeConfigGuard::assertSafe((string) config('app.env'), (string) config('broadcasting.default'), (array) config('reverb.apps.apps.0.allowed_origins', []));

        // Channel authorization callbacks (routes/channels.php), registered on the configured broadcaster.
        require base_path('routes/channels.php');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
