<?php

namespace App\Modules\WalkIns\Contracts;

use Carbon\CarbonImmutable;

/**
 * The "doctor is running late" notice for walk-in patients (increment 5.8, D-P). Presence calls it once per break, when the break reaches its
 * expected end while the doctor is still on it. In-app only; the text is generic and names no patient, queue or reason.
 */
interface BreakOverrunNotices
{
    /** @return int the number of in-app notices written (one per user of a waiting or registered patient entry) */
    public function notifyWaitingPatients(string $doctorProfileId, string $locationId, CarbonImmutable $now): int;
}
