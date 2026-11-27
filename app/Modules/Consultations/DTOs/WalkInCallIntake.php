<?php

namespace App\Modules\Consultations\DTOs;

use Carbon\CarbonImmutable;

/** What the walk-in module tells Consultations when it calls a patient. The patient is null for a guest. Ids only, no personal data. */
final class WalkInCallIntake
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $doctorProfileId,
        public readonly ?string $patientProfileId,
        public readonly string $clinicLocationId,
        public readonly CarbonImmutable $calledAt,
    ) {}
}
