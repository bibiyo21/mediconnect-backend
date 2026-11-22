<?php

namespace App\Modules\Presence\Enums;

/**
 * The only presence values a patient or the public may see (phase 5 plan Q13). The stored
 * status is richer; reasons, times, actors and devices are never public.
 * ONLINE is reserved for D-28 (online presence), which is not owner-approved yet.
 */
enum PublicPresenceStatus: string
{
    case In = 'IN';
    case Busy = 'BUSY';
    case OnBreak = 'ON_BREAK';
    /** TEMPORARILY_UNAVAILABLE. */
    case Away = 'AWAY';
    /** OUT and NOT_CHECKED_IN look the same from outside. */
    case NotIn = 'NOT_IN';

    public static function of(PresenceStatus $status): self
    {
        return match ($status) {
            PresenceStatus::In => self::In,
            PresenceStatus::Busy => self::Busy,
            PresenceStatus::OnBreak => self::OnBreak,
            PresenceStatus::TemporarilyUnavailable => self::Away,
            PresenceStatus::Out, PresenceStatus::NotCheckedIn => self::NotIn,
        };
    }
}
