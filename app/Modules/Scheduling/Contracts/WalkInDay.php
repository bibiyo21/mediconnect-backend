<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\DTOs\WalkInDayFacts;

/**
 * The schedule's view of a walk-in day (increment 5.5a). The WalkIns and Clinics modules use it for queue capacity, cutoff
 * and the wait estimator's blocked time; the slot generator and the booking tables stay inside Scheduling.
 */
interface WalkInDay
{
    /** Whether the doctor service has an ACTIVE walk-in or both-mode configuration in force on the local date. */
    public function offersWalkIn(string $doctorServiceId, string $localDate): bool;

    /**
     * @param  string|null  $clinicServiceId  limits the shifts to modes whose doctor service is for this clinic service (null = services with none)
     * @param  bool  $anyClinicService  true ignores $clinicServiceId and counts every walk-in shift of the doctor at the location
     * @param  int  $graceFallbackMinutes  the late grace to use when no scheduling policy sets one (the caller's setting; this module reads no other module's config)
     */
    public function forDoctorAtLocation(string $doctorId, string $locationId, string $localDate, ?string $clinicServiceId = null, bool $anyClinicService = false, int $graceFallbackMinutes = 15): WalkInDayFacts;
}
