<?php

namespace App\Modules\WalkIns\DTOs;

use App\Modules\WalkIns\Enums\EntryStatus;

/**
 * An entry's facts under its queue and entry locks, for a consultation's start or end. Ids and codes only: no guest name, phone or note.
 * `doctorProfileId` is the entry's assigned doctor, else the queue's doctor. `localDate` is the queue's date (Y-m-d).
 * `doctorActive` is the doctor's verified onboarding (ACTIVE); `locationActive` is the location and its clinic both ACTIVE (the check-in rules).
 */
final class WalkInEntryFacts
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $queueId,
        public readonly string $doctorProfileId,
        public readonly ?string $patientProfileId,
        public readonly string $locationId,
        public readonly string $timezone,
        public readonly string $localDate,
        public readonly EntryStatus $status,
        public readonly bool $queueClosed,
        public readonly bool $queueIsToday,
        public readonly bool $assignmentActive,
        public readonly bool $doctorActive,
        public readonly bool $locationActive,
    ) {}
}
