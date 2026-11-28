<?php

namespace App\Modules\Appointments\Contracts;

use App\Modules\Appointments\DTOs\AppointmentRef;
use Carbon\CarbonImmutable;

/**
 * What a doctor's check-out does to appointments (increment 5.8, D-E): flag, never cancel. Appointments owns its tables; Presence reaches
 * them only through here.
 */
interface CheckOutAppointments
{
    /**
     * Live appointments of the doctor at the location that run after $fromUtc and start before $toUtc, earliest first. Advisory (no lock):
     * for the preview only.
     *
     * @return list<AppointmentRef>
     */
    public function remaining(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array;

    /**
     * The latest end among the doctor's live appointments at the location that overlap $fromUtc..$toUtc, or null when there are none. Advisory
     * (no lock): the auto check-out uses it to find when the doctor's last booked in-person work ends today (5.8 V-M1).
     */
    public function lastEndsAt(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): ?CarbonImmutable;

    /**
     * Sets needs_rescheduling on those same appointments (FOR UPDATE, ascending id) and opens one DOCTOR_CHECKOUT rescheduling task each. The
     * task insert is idempotent through the open-task partial unique index, so a second check-out the same day creates none. Must run inside
     * the caller's transaction, after the doctor's session lock. Never cancels.
     *
     * @return array{flagged: int, tasks_created: int}
     */
    public function flagForCheckOut(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array;
}
