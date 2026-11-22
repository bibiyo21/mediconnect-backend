<?php

namespace App\Modules\Discovery\Providers;

use App\Modules\Clinics\Events\ClinicDirectoryChanged;
use App\Modules\Discovery\Console\EvaluateSearchCommand;
use App\Modules\Discovery\Console\InstallSearchCommand;
use App\Modules\Discovery\Console\MeasureIndexLatencyCommand;
use App\Modules\Discovery\Console\PerformanceDatasetCommand;
use App\Modules\Discovery\Console\RebuildSearchCommand;
use App\Modules\Discovery\Console\SearchAnalyticsPartitionsCommand;
use App\Modules\Discovery\Console\SearchStatusCommand;
use App\Modules\Discovery\Listeners\QueueSearchIndexing;
use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Modules\Scheduling\Events\AvailabilityUpdated;
use App\Modules\Specialties\Events\SpecialtyCatalogChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Doctor discovery and search (CLAUDE.md §3.1 module 7, Phase 3). */
final class DiscoveryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DoctorDirectoryChanged::class, [QueueSearchIndexing::class, 'handleDoctor']);
        Event::listen(ClinicDirectoryChanged::class, [QueueSearchIndexing::class, 'handleClinic']);
        Event::listen(SpecialtyCatalogChanged::class, [QueueSearchIndexing::class, 'handleSpecialty']);
        Event::listen(AvailabilityUpdated::class, [QueueSearchIndexing::class, 'handleAvailability']);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallSearchCommand::class, SearchStatusCommand::class, RebuildSearchCommand::class, SearchAnalyticsPartitionsCommand::class, EvaluateSearchCommand::class, PerformanceDatasetCommand::class, MeasureIndexLatencyCommand::class]);
        }
    }
}
