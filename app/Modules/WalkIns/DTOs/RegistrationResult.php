<?php

namespace App\Modules\WalkIns\DTOs;

use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;

/** The committed entry, its queue, and the estimate computed under the queue lock. $replayed is true when an earlier request with the same key made the entry. */
final class RegistrationResult
{
    public function __construct(
        public readonly WalkInQueueEntry $entry,
        public readonly WalkInQueue $queue,
        public readonly ?WaitEstimate $estimate,
        public readonly bool $replayed = false,
    ) {}
}
