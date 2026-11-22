<?php

namespace App\Modules\WalkIns\Providers;

use App\Modules\WalkIns\Estimation\EstimationConfig;
use App\Modules\WalkIns\Estimation\EstimatorSettings;
use Illuminate\Support\ServiceProvider;

/** Walk-in queues (Phase 5, architecture.md §10). */
final class WalkInsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Not a singleton: the settings are read from config each time an estimator is resolved.
        $this->app->bind(EstimatorSettings::class, fn () => EstimatorSettings::fromConfig());
    }

    public function boot(): void
    {
        // Fail loud: a bad estimation setting stops the application at boot.
        EstimationConfig::fromConfig();
    }
}
