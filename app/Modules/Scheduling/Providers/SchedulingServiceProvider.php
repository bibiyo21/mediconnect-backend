<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Clinics\Events\ClinicDirectoryChanged;
use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Modules\Doctors\Events\DoctorServiceReplaced;
use App\Modules\Doctors\Events\DoctorServicesEnded;
use App\Modules\Scheduling\Console\GenerateAvailabilityCommand;
use App\Modules\Scheduling\Contracts\AvailabilitySummaries;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Scheduling\Events\SchedulingChanged;
use App\Modules\Scheduling\Listeners\FollowServiceChanges;
use App\Modules\Scheduling\Listeners\QueueAvailabilityRefresh;
use App\Modules\Scheduling\Services\AvailabilitySummaryService;
use App\Modules\Scheduling\Services\WalkInDayService;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Scheduling (Phase 4, architecture.md §8). */
final class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AvailabilitySummaries::class, AvailabilitySummaryService::class);
        $this->app->bind(WalkInDay::class, WalkInDayService::class);
    }

    public function boot(): void
    {
        // Holds lock scarce times: 10 a minute per user (Phase 4 plan Q11).
        RateLimiter::for('holds', fn (Request $request) => Limit::perMinute(10)->by('holds:'.($request->user()?->getAuthIdentifier() ?? Fingerprint::ip($request->ip()))));

        Event::listen(DoctorServiceReplaced::class, [FollowServiceChanges::class, 'replaced']);
        Event::listen(DoctorServicesEnded::class, [FollowServiceChanges::class, 'ended']);

        // Availability refresh triggers (architecture.md §8.2), relayed from the outbox.
        Event::listen(SchedulingChanged::class, [QueueAvailabilityRefresh::class, 'scheduling']);
        Event::listen(DoctorDirectoryChanged::class, [QueueAvailabilityRefresh::class, 'doctor']);
        Event::listen(ClinicDirectoryChanged::class, [QueueAvailabilityRefresh::class, 'clinic']);

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateAvailabilityCommand::class]);
        }

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
