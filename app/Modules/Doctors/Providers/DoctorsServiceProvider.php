<?php

namespace App\Modules\Doctors\Providers;

use App\Modules\Clinics\Events\DoctorAssignmentEnded;
use App\Modules\Doctors\Listeners\EndServicesWhenAssignmentEnds;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Modules\Doctors\Observers\DirectoryObserver;
use App\Shared\Services\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class DoctorsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Credential uploads: generous for a real applicant, tight for abuse.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(30)->by('uploads:'.($request->user()?->getAuthIdentifier() ?? Fingerprint::ip($request->ip()))));
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(30)->by('downloads:'.Fingerprint::ip($request->ip())));

        Event::listen(DoctorAssignmentEnded::class, EndServicesWhenAssignmentEnds::class);

        // Search indexing (Phase 3): models feeding the public doctor documents record outbox events.
        foreach ([DoctorProfile::class, DoctorSpecialty::class, DoctorService::class] as $model) {
            $model::observe(DirectoryObserver::class);
        }

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
