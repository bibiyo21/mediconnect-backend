<?php

namespace App\Modules\Clinics\Providers;

use App\Modules\Clinics\Contracts\ClinicAccess;
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
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
