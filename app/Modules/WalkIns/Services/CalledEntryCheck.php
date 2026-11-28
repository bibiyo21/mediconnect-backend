<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\WalkIns\Contracts\CalledEntries;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Models\WalkInQueueEntry;

final class CalledEntryCheck implements CalledEntries
{
    public function hasCalledFor(string $doctorProfileId): bool
    {
        return WalkInQueueEntry::query()
            ->where('assigned_doctor_profile_id', $doctorProfileId)
            ->where('status', EntryStatus::Called->value)
            ->exists();
    }
}
