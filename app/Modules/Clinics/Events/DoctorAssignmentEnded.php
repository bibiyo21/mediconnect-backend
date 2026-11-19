<?php

namespace App\Modules\Clinics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A doctor no longer practises at a location. The Doctors module ends the
 * doctor's in-person services there (bookings, from Phase 4, follow §8.3:
 * never silently cancelled).
 */
final class DoctorAssignmentEnded
{
    use Dispatchable;

    public function __construct(
        public readonly string $doctorProfileId,
        public readonly string $clinicLocationId,
        public readonly ?string $actorId,
    ) {}
}
