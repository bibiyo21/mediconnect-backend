<?php

namespace App\Modules\WalkIns\Providers;

use App\Modules\Presence\Events\PresenceChanged;
use App\Modules\WalkIns\Contracts\CheckOutQueues;
use App\Modules\WalkIns\Contracts\WalkInConsultationEntries;
use App\Modules\WalkIns\Estimation\EstimationConfig;
use App\Modules\WalkIns\Estimation\EstimatorSettings;
use App\Modules\WalkIns\Listeners\FanOutOnPresenceChange;
use App\Modules\WalkIns\Services\CheckOutQueues as CheckOutQueuesService;
use App\Modules\WalkIns\Services\ConsultationEntries;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Walk-in queues (Phase 5, architecture.md §10). */
final class WalkInsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Not a singleton: the settings are read from config each time an estimator is resolved.
        $this->app->bind(EstimatorSettings::class, fn () => EstimatorSettings::fromConfig());
        $this->app->bind(WalkInConsultationEntries::class, ConsultationEntries::class);
        $this->app->bind(CheckOutQueues::class, CheckOutQueuesService::class);
    }

    public function boot(): void
    {
        // Fail loud: a bad estimation setting stops the application at boot.
        EstimationConfig::fromConfig();

        $user = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? Fingerprint::ip($request->ip()));

        // Registration (decision 156): the path is chosen by the body, so a guest (staff or doctor) gets the larger bucket. Values are in config/walkins.php.
        RateLimiter::for('walkin-register', fn (Request $request) => Limit::perMinute($request->has('guest')
            ? (int) config('walkins.limits.register_staff_per_minute')
            : (int) config('walkins.limits.register_patient_per_minute'))->by('walkin-register:'.($request->has('guest') ? 'staff:' : 'patient:').$user($request)));
        RateLimiter::for('walkin-write', fn (Request $request) => Limit::perMinute((int) config('walkins.limits.write_per_minute'))->by('walkin-write:'.$user($request)));
        RateLimiter::for('walkin-search', fn (Request $request) => Limit::perMinute((int) config('walkins.limits.search_per_minute'))->by('walkin-search:'.$user($request)));
        RateLimiter::for('walkin-read', fn (Request $request) => Limit::perMinute((int) config('walkins.limits.read_per_minute'))->by('walkin-read:'.$user($request)));

        // Presence changes refresh the estimates of the doctor's live queues (5.8, D-M).
        Event::listen(PresenceChanged::class, FanOutOnPresenceChange::class);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
