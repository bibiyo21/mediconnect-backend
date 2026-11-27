<?php

namespace App\Modules\Consultations\DTOs;

use Carbon\CarbonImmutable;

/**
 * What Consultations gives the care-relationship service at a walk-in start. Ids, a timezone and an instant only: no name, phone or note.
 * `patientProfileId` is null for a guest, who gets no grant.
 */
final class WalkInCareGrant
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $doctorProfileId,
        public readonly ?string $patientProfileId,
        public readonly string $timezone,
        public readonly CarbonImmutable $startedAt,
    ) {}
}
