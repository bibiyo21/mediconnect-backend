<?php

namespace App\Modules\WalkIns\Contracts;

use App\Modules\WalkIns\DTOs\CheckOutQueueFacts;
use App\Modules\WalkIns\DTOs\CheckOutQueueResult;
use App\Modules\WalkIns\Enums\CheckOutDisposition;

/**
 * The walk-in side of a doctor's check-out (increment 5.8, D-B, D-D). Presence owns the transaction and the session lock; WalkIns owns its
 * tables. lockForCheckOut and apply run inside the caller's database transaction, after the doctor's session row is locked FOR UPDATE.
 */
interface CheckOutQueues
{
    /**
     * Locks the doctor's queues for the local day (FOR UPDATE, ascending id), then their held entries (FOR UPDATE, ascending id), and
     * returns the facts. Decides nothing: the caller decides.
     */
    public function lockForCheckOut(string $doctorProfileId, string $locationId, string $localDate): CheckOutQueueFacts;

    /** The same facts without locks, for the check-out preview. Advisory only. */
    public function peek(string $doctorProfileId, string $locationId, string $localDate): CheckOutQueueFacts;

    /**
     * Pauses the doctor's OPEN queues and applies the disposition to the entries in the locked facts: HOLD leaves them in place with an
     * in-app notice; NOTIFY_AND_CANCEL cancels them (DOCTOR_CHECKED_OUT, with cancelForWalkIn and the staff-cancel notice). A guest entry
     * needs $guestsInformed. $actorUserId is null only for the SYSTEM actor.
     */
    public function apply(CheckOutQueueFacts $facts, CheckOutDisposition $disposition, ?string $actorUserId, bool $guestsInformed): CheckOutQueueResult;
}
