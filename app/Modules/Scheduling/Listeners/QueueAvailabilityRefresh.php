<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Clinics\Events\ClinicDirectoryChanged;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Modules\Scheduling\Events\SchedulingChanged;
use App\Modules\Scheduling\Jobs\RefreshDoctorAvailability;

/**
 * Turns input changes into availability refreshes (architecture.md §8.2
 * triggers): scheduling changes, doctor changes (status, services) and clinic
 * changes (status, locations, opening hours, holidays, assignments).
 */
final class QueueAvailabilityRefresh
{
    public function scheduling(SchedulingChanged $event): void
    {
        RefreshDoctorAvailability::dispatch($event->id);
    }

    public function doctor(DoctorDirectoryChanged $event): void
    {
        RefreshDoctorAvailability::dispatch($event->id);
    }

    public function clinic(ClinicDirectoryChanged $event): void
    {
        $locations = ClinicLocation::where('clinic_id', $event->id)->select('id');
        // An ended assignment reaches the doctor through DoctorDirectoryChanged and the deactivated service modes.
        foreach (DoctorClinicAssignment::whereIn('clinic_location_id', $locations)->where('status', 'ACTIVE')->pluck('doctor_profile_id')->unique() as $doctorId) {
            RefreshDoctorAvailability::dispatch((string) $doctorId);
        }
    }
}
