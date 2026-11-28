<?php

namespace App\Modules\Presence\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A doctor's presence at a location changed and committed (check-in, break, unavailable, available or check-out; increment 5.8, D-M). A plain
 * domain event, not a broadcast: WalkIns listens and refreshes the estimates of the queues the doctor serves there today. Carries ids only.
 */
final class PresenceChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $doctorProfileId,
        public readonly string $clinicLocationId,
    ) {}
}
