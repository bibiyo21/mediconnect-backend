<?php

namespace App\Modules\WalkIns\Contracts;

use Carbon\CarbonImmutable;

/**
 * When the doctor's walk-in queues at a location last had activity (increment 5.8-3, HAG-46). Read only, ids in, one instant out: the
 * automatic check-out waits for an idle period before it closes a session from an earlier day.
 */
interface QueueActivity
{
    /** The latest queue event (register, call, start, complete, skip and the like) on the doctor's queues at the location, or null. */
    public function lastActivityAt(string $doctorProfileId, string $locationId): ?CarbonImmutable;
}
