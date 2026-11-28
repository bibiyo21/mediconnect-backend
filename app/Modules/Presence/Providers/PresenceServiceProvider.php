<?php

namespace App\Modules\Presence\Providers;

use App\Modules\Presence\Console\AutoCheckOutCommand;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\Contracts\PresenceVisibility;
use App\Modules\Presence\Services\PresenceAdmissionService;
use App\Modules\Presence\Services\PresenceCache;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Doctor presence (Phase 5, architecture.md §9). */
final class PresenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PresenceAdmission::class, PresenceAdmissionService::class);
        $this->app->bind(PresenceVisibility::class, PresenceCache::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AutoCheckOutCommand::class]);
        }
    }

    public function boot(): void
    {
        $user = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? Fingerprint::ip($request->ip()));

        RateLimiter::for('presence-write', fn (Request $request) => Limit::perMinute(30)->by('presence-write:'.$user($request)));
        RateLimiter::for('presence-read', fn (Request $request) => Limit::perMinute(120)->by('presence-read:'.$user($request)));
        RateLimiter::for('presence-public', fn (Request $request) => Limit::perMinute(60)->by('presence-public:'.Fingerprint::ip($request->ip())));

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
