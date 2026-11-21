<?php

namespace App\Modules\Doctors\Events;

/** One or more services ended without a replacement. Dispatched synchronously after the change. */
final class DoctorServicesEnded
{
    /** @param list<string> $serviceIds */
    public function __construct(public readonly string $doctorProfileId, public readonly array $serviceIds) {}
}
