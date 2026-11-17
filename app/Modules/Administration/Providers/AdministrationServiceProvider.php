<?php

namespace App\Modules\Administration\Providers;

use App\Modules\Administration\Contracts\Settings;
use App\Modules\Administration\Services\DatabaseSettings;
use Illuminate\Support\ServiceProvider;

final class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class, DatabaseSettings::class);
    }
}
