<?php

namespace App\Modules\WalkIns\Contracts;

use App\Modules\WalkIns\DTOs\WalkInEntryFacts;
use Carbon\CarbonImmutable;

/**
 * The walk-in side of a consultation's start and end (increment 5.7). Consultations owns the transaction and the decisions; WalkIns owns
 * its tables. Every method must run inside a database transaction that the caller opened, after the doctor's presence lock.
 */
interface WalkInConsultationEntries
{
    /**
     * Locks the entry's queue FOR UPDATE, then the entry FOR UPDATE (the Phase 5 order), and returns the facts the caller decides on.
     * Locks nothing else and decides no state itself: the caller does.
     */
    public function lockEntry(string $entryId): WalkInEntryFacts;

    /** CALLED to IN_CONSULTATION at the start instant, with a STARTED event. The entry must already be locked by lockEntry. */
    public function markInConsultation(string $entryId, string $actorUserId, CarbonImmutable $at): void;

    /** IN_CONSULTATION to COMPLETED at the end instant, with a COMPLETED event. The entry must already be locked by lockEntry. */
    public function markCompleted(string $entryId, string $actorUserId, CarbonImmutable $at): void;
}
