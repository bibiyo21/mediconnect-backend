<?php

namespace App\Modules\Doctors\Events;

/**
 * A service was changed (for example a new fee): the old row ended and a
 * replacement started (increment 2.10). Dispatched synchronously inside the
 * change's transaction, so dependent records (scheduling service modes,
 * Phase 4 plan Q8) move to the new row atomically.
 */
final class DoctorServiceReplaced
{
    public function __construct(public readonly string $doctorProfileId, public readonly string $previousServiceId, public readonly string $serviceId) {}
}
