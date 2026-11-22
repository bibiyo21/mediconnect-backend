<?php

namespace App\Modules\Presence\Enums;

/** What is true about a doctor right now (CLAUDE.md §8.1). */
enum PresenceStatus: string
{
    /** No open session: never stored on a session row. */
    case NotCheckedIn = 'NOT_CHECKED_IN';
    case In = 'IN';
    case Out = 'OUT';
    case OnBreak = 'ON_BREAK';
    case TemporarilyUnavailable = 'TEMPORARILY_UNAVAILABLE';
    /** Derived from an IN_PROGRESS consultation: never stored. */
    case Busy = 'BUSY';

    /**
     * The statuses a session row may hold.
     *
     * @return list<self>
     */
    public static function stored(): array
    {
        return [self::In, self::Out, self::OnBreak, self::TemporarilyUnavailable];
    }

    public function isStored(): bool
    {
        return in_array($this, self::stored(), true);
    }
}
