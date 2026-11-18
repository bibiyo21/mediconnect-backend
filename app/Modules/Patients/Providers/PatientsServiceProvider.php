<?php

namespace App\Modules\Patients\Providers;

use App\Modules\Patients\Contracts\PatientProfiles;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Patients\Policies\PatientProfilePolicy;
use App\Modules\Patients\Services\PatientProfileService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PatientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PatientProfiles::class, PatientProfileService::class);
    }

    public function boot(): void
    {
        Gate::policy(PatientProfile::class, PatientProfilePolicy::class);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
