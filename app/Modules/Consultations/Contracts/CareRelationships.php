<?php

namespace App\Modules\Consultations\Contracts;

use App\Modules\Consultations\DTOs\WalkInCareGrant;

/** Care relationships (CLAUDE.md §20.3): the basis on which a doctor may read a patient's records. */
interface CareRelationships
{
    public const SOURCE_WALK_IN = 'WALK_IN';

    /**
     * Grants the doctor access to the patient from the start instant until the end of that local day (the clinic's timezone) plus
     * consultations.care_access_days. Called at consultation START (B10, increment 5.7), as the last write of the start transaction, never at
     * call. Idempotent per entry. Returns the care relationship id, or null for a guest. A doctor's own patient profile is refused.
     */
    public function grantForWalkIn(WalkInCareGrant $grant): ?string;

    /** Revokes the ACTIVE care relationship of a source (status REVOKED, revoked_at now). Idempotent; an expired or missing one is left alone. */
    public function revokeForSource(string $sourceType, string $sourceId): void;
}
