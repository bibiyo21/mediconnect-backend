<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\Enums\PresenceStatus;

/**
 * The legal presence moves (architecture.md §9.2). BUSY is derived, never a source or a
 * target. Check-out (to OUT, from any open state) is added with increment 5.8; a closed
 * session cannot move: coming back is a new session.
 */
final class PresenceTransitions
{
    /** @var array<string, list<PresenceStatus>> */
    private const TABLE = [
        'NOT_CHECKED_IN' => [PresenceStatus::In],
        'IN' => [PresenceStatus::OnBreak, PresenceStatus::TemporarilyUnavailable, PresenceStatus::Out],
        'ON_BREAK' => [PresenceStatus::In, PresenceStatus::Out],
        'TEMPORARILY_UNAVAILABLE' => [PresenceStatus::In, PresenceStatus::Out],
        'OUT' => [],
        'BUSY' => [],
    ];

    public static function allows(PresenceStatus $from, PresenceStatus $to): bool
    {
        return in_array($to, self::TABLE[$from->value], true);
    }
}
