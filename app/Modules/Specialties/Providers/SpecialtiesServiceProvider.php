<?php

namespace App\Modules\Specialties\Providers;

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
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
