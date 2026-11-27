<?php

namespace App\Modules\Consultations\Contracts;

/**
 * The doctor's running consultation, for derived BUSY (plan 5.7, D-H) and for refusing breaks and unavailability (D-I). Advisory when read
 * without a lock; callers that must decide take their own lock first (the doctor's presence row).
 */
interface ActiveConsultations
{
    /** The id of the doctor's IN_PROGRESS consultation, or null. INTERRUPTED is not BUSY. */
    public function inProgressFor(string $doctorProfileId): ?string;
}
