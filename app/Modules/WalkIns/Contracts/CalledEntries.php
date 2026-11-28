<?php

namespace App\Modules\WalkIns\Contracts;

/**
 * Whether a doctor has a walk-in patient CALLED to them, in any queue and on any day (increment 5.8, HAG-43). Presence reads it under the
 * doctor's session lock before a break starts: a called patient is standing at the desk, so the break waits. IN_CONSULTATION is not
 * decided here (Consultations owns that), so an INTERRUPTED consultation does not block a break.
 */
interface CalledEntries
{
    public function hasCalledFor(string $doctorProfileId): bool;
}
