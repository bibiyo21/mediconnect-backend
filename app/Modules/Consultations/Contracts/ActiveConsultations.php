<?php

namespace App\Modules\Consultations\Contracts;

use App\Modules\Consultations\DTOs\UnfinishedConsultation;

/**
 * The doctor's running consultation, for derived BUSY (plan 5.7, D-H) and for refusing breaks and unavailability (D-I). Advisory when read
 * without a lock; callers that must decide take their own lock first (the doctor's presence row).
 */
interface ActiveConsultations
{
    /** The id of the doctor's IN_PROGRESS consultation, or null. INTERRUPTED is not BUSY. */
    public function inProgressFor(string $doctorProfileId): ?string;

    /**
     * The doctor's IN_PROGRESS or INTERRUPTED consultation, or null (5.8 check-out block, D-C). Advisory without a lock: the caller
     * holds the doctor's presence row FOR UPDATE, which every consultation start, resume, end and interrupt also needs.
     */
    public function unfinishedFor(string $doctorProfileId): ?UnfinishedConsultation;
}
