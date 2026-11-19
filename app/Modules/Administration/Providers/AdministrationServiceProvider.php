<?php

namespace App\Modules\Administration\Providers;

use App\Modules\Administration\Contracts\Settings;
use App\Modules\Administration\Services\DatabaseSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class, DatabaseSettings::class);
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
