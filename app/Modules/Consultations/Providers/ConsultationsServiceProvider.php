<?php

namespace App\Modules\Consultations\Providers;

use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\Contracts\ConsultationIntake;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Policies\ConsultationPolicy;
use App\Modules\Consultations\Services\ActiveConsultationsService;
use App\Modules\Consultations\Services\CareRelationshipService;
use App\Modules\Consultations\Services\ConsultationIntakeService;
use App\Modules\Consultations\Services\ConsultationsConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Consultations and care relationships (Phase 5). */
final class ConsultationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConsultationsConfig::class, fn () => ConsultationsConfig::fromConfig());
        $this->app->bind(CareRelationships::class, CareRelationshipService::class);
        $this->app->bind(ConsultationIntake::class, ConsultationIntakeService::class);
        $this->app->bind(ActiveConsultations::class, ActiveConsultationsService::class);
    }

    public function boot(): void
    {
        // Fail loud: a bad setting stops the application at boot.
        ConsultationsConfig::fromConfig();

        Gate::policy(Consultation::class, ConsultationPolicy::class);

        RateLimiter::for('consultation-write', fn (Request $request) => Limit::perMinute((int) config('consultations.limits.write_per_minute'))
            ->by('consultation-write:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
