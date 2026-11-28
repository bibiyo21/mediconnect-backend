<?php

namespace App\Modules\Appointments\Providers;

use App\Modules\Appointments\Contracts\CheckOutAppointments;
use App\Modules\Appointments\Services\CheckOutAppointmentsService;
use Illuminate\Support\ServiceProvider;

/** Appointments (Phase 5 check-out flagging; booking arrives in Phase 6). */
final class AppointmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CheckOutAppointments::class, CheckOutAppointmentsService::class);
    }
}
