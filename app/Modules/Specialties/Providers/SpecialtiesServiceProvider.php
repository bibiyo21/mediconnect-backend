<?php

namespace App\Modules\Specialties\Providers;

use App\Modules\Specialties\Models\Specialty;
use App\Modules\Specialties\Models\Subspecialty;
use App\Modules\Specialties\Observers\CatalogObserver;
use App\Modules\Specialties\Services\SpecialtyCatalog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SpecialtiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SpecialtyCatalog::class);
    }

    public function boot(): void
    {
        Specialty::observe(CatalogObserver::class);
        Subspecialty::observe(CatalogObserver::class);
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
