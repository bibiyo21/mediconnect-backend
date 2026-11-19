<?php

namespace App\Modules\Doctors\Providers;

use App\Modules\Clinics\Events\DoctorAssignmentEnded;
use App\Modules\Doctors\Listeners\EndServicesWhenAssignmentEnds;
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

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
