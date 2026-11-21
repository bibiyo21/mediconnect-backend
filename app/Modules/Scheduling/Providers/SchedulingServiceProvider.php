<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Doctors\Events\DoctorServiceReplaced;
use App\Modules\Doctors\Events\DoctorServicesEnded;
use App\Modules\Scheduling\Listeners\FollowServiceChanges;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Scheduling (Phase 4, architecture.md §8). */
final class SchedulingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DoctorServiceReplaced::class, [FollowServiceChanges::class, 'replaced']);
        Event::listen(DoctorServicesEnded::class, [FollowServiceChanges::class, 'ended']);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }
}
