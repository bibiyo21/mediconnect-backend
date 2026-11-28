<?php

namespace App\Modules\Appointments\DTOs;

use Carbon\CarbonImmutable;

/** A live appointment that a check-out touches: its public id and start instant (UTC). No patient data. */
final readonly class AppointmentRef
{
    public function __construct(
        public string $id,
        public CarbonImmutable $startsAt,
    ) {}
}
