<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\ReconcileRealtime;
use App\Modules\Notifications\Console\TerminateRealtime;
use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Contracts\TerminatesRealtimeSessions;
use App\Modules\Notifications\Push\DisabledPushGateway;
use App\Modules\Notifications\Push\LogPushGateway;
use App\Modules\Notifications\Push\PushConfigGuard;
use App\Modules\Notifications\Realtime\RealtimeConfigGuard;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Services\OneTimeMessageSender;
use App\Modules\Notifications\Services\RealtimeSessions;
use App\Modules\Notifications\Sms\DisabledSmsGateway;
use App\Modules\Notifications\Sms\MailPreviewSmsGateway;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SendsOneTimeMessages::class, OneTimeMessageSender::class);

        $this->app->singleton(SendsNotifications::class, NotificationService::class);

        $this->app->singleton(TerminatesRealtimeSessions::class, RealtimeSessions::class);

        // Push (plan Q11): only the log (LOCAL) and disabled drivers exist until the owner provides Firebase credentials.
        $this->app->singleton(PushGateway::class, fn () => match ($driver = (string) config('services.push.driver')) {
            'log' => new LogPushGateway,
            'disabled' => new DisabledPushGateway,
            default => throw new InvalidArgumentException("Unknown PUSH_DRIVER [{$driver}]."),
        });

        $this->app->singleton(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'mail-preview' => new MailPreviewSmsGateway,
            default => new DisabledSmsGateway,
        });
    }

    public function boot(): void
    {
        // Channel subscriptions: 60 a minute per user (a client joins a handful of channels at connect).
        RateLimiter::for('broadcast-auth', fn (Request $request) => Limit::perMinute(60)->by('broadcast-auth:'.($request->user()?->getAuthIdentifier() ?? Fingerprint::ip($request->ip()))));

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileRealtime::class, TerminateRealtime::class]);
        }

        PushConfigGuard::assertSafe((string) config('app.env'), (string) config('services.push.driver'));
        RealtimeConfigGuard::assertSafe((string) config('app.env'), (string) config('broadcasting.default'), (array) config('reverb.apps.apps.0.allowed_origins', []), (bool) config('reverb.servers.reverb.scaling.enabled', false));

        // Channel authorization callbacks (routes/channels.php), registered on the configured broadcaster.
        require base_path('routes/channels.php');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
