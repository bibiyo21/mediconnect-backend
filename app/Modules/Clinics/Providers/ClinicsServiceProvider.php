<?php

namespace App\Modules\Clinics\Providers;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicHoliday;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicOperatingHour;
use App\Modules\Clinics\Models\ClinicService;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Observers\DirectoryObserver;
use App\Modules\Clinics\Services\ClinicAccessService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ClinicsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClinicAccess::class, ClinicAccessService::class);
    }

    public function boot(): void
    {
        // Search indexing (Phase 3): public clinic data and assignments record outbox events.
        foreach ([Clinic::class, ClinicLocation::class, ClinicService::class, DoctorClinicAssignment::class, ClinicOperatingHour::class, ClinicHoliday::class] as $model) {
            $model::observe(DirectoryObserver::class);
        }
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
