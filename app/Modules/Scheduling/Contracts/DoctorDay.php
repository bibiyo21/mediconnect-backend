<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\DTOs\DoctorDayShift;

/**
 * The schedule's view of one doctor's day at one location, for the doctor's own dashboard (increment 5.9-B, M2). Unlike WalkInDay it lists
 * every in-person shift, whatever its service mode, so an appointment-only shift is shown too. Read-only.
 */
interface DoctorDay
{
    /**
     * Every in-person shift of the doctor at the location on the local date, one entry per shift, ordered by start. Exceptions and special
     * hours are applied; a shift with no effective hours that day is left out. Breaks outside a shift's effective hours are left out.
     *
     * @return list<DoctorDayShift>
     */
    public function forDoctorAtLocation(string $doctorId, string $locationId, string $localDate): array;
}
