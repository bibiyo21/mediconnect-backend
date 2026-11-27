<?php

namespace App\Modules\Consultations\Providers;

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\Contracts\ConsultationIntake;
use App\Modules\Consultations\Services\CareRelationshipService;
use App\Modules\Consultations\Services\ConsultationIntakeService;
use App\Modules\Consultations\Services\ConsultationsConfig;
use Illuminate\Support\ServiceProvider;

/** Consultations and care relationships (Phase 5). */
final class ConsultationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConsultationsConfig::class, fn () => ConsultationsConfig::fromConfig());
        $this->app->bind(CareRelationships::class, CareRelationshipService::class);
        $this->app->bind(ConsultationIntake::class, ConsultationIntakeService::class);
    }

    public function boot(): void
    {
        // Fail loud: a bad setting stops the application at boot.
        ConsultationsConfig::fromConfig();
    }
}
