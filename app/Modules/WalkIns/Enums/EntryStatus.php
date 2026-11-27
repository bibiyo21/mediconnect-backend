<?php

namespace App\Modules\WalkIns\Enums;

/** One patient's place in a walk-in queue (CLAUDE.md §9.2, architecture.md §10.3). */
enum EntryStatus: string
{
    case Registered = 'REGISTERED';
    case Waiting = 'WAITING';
    case Called = 'CALLED';
    case InConsultation = 'IN_CONSULTATION';
    case Completed = 'COMPLETED';
    case Skipped = 'SKIPPED';
    case NoShow = 'NO_SHOW';
    case Cancelled = 'CANCELLED';

    /**
     * Entries that still hold a place: exactly the predicate of the
     * uq_one_live_entry_per_patient index (a test keeps them equal).
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Registered, self::Waiting, self::Called, self::InConsultation];
    }

    /**
     * Entries that keep a patient's one place in a queue: the live ones plus SKIPPED (decision 94). Exactly the predicate of the
     * uq_one_held_entry_per_patient index (a test keeps them equal).
     *
     * @return list<self>
     */
    public static function held(): array
    {
        return [...self::live(), self::Skipped];
    }

    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }
}
