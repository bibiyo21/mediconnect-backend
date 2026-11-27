<?php

namespace App\Modules\Consultations\Contracts;

use Carbon\CarbonImmutable;

/** Care relationships (CLAUDE.md §20.3): the basis on which a doctor may read a patient's records. */
interface CareRelationships
{
    public const SOURCE_WALK_IN = 'WALK_IN';

    /**
     * Grants the entry's doctor access to the entry's patient, from $startedAt until the end of that day (clinic timezone) plus
     * consultations.care_access_days. Called at consultation START (B10, increment 5.7), never at call. Idempotent per entry.
     * Returns the care relationship id, or null for a guest (no patient profile, so nothing to grant).
     */
    public function grantForWalkIn(string $entryId, CarbonImmutable $startedAt): ?string;

    /** Revokes the ACTIVE care relationship of a source (status REVOKED, revoked_at now). Idempotent; an expired or missing one is left alone. */
    public function revokeForSource(string $sourceType, string $sourceId): void;
}
