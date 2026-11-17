<?php

namespace App\Modules\Patients\Providers;

use App\Modules\Patients\Contracts\PatientProfiles;
use App\Modules\Patients\Services\PatientProfileService;
use Illuminate\Support\ServiceProvider;

final class PatientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PatientProfiles::class, PatientProfileService::class);
    }
}
