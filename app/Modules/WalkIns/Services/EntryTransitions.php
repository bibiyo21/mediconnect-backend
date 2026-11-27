<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\WalkIns\Enums\EntryStatus;

/**
 * The legal walk-in entry moves (5.6 plan D9, D10, D15; architecture.md §10.3), in the style of PresenceTransitions. Pure: it knows
 * statuses only. Rules that need data (head of the queue, recall limit, today's date, presence) live with the action that
 * makes the move.
 *
 * - CALLED to SKIPPED is the skip; CALLED to NO_SHOW is allowed only once the recall limit is reached (an action rule).
 * - SKIPPED to WAITING is the return (staff or doctor); SKIPPED to NO_SHOW is always allowed.
 * - Cancel is allowed from REGISTERED, WAITING and SKIPPED (D15), never from CALLED or later.
 * - There is no CALLED to WAITING: a called patient who does not come is skipped, then returned.
 */
final class EntryTransitions
{
    /** @var array<string, list<EntryStatus>> */
    private const TABLE = [
        'REGISTERED' => [EntryStatus::Waiting, EntryStatus::Cancelled],
        'WAITING' => [EntryStatus::Called, EntryStatus::Cancelled],
        'CALLED' => [EntryStatus::InConsultation, EntryStatus::Skipped, EntryStatus::NoShow],
        'IN_CONSULTATION' => [EntryStatus::Completed],
        'SKIPPED' => [EntryStatus::Waiting, EntryStatus::NoShow, EntryStatus::Cancelled],
        'COMPLETED' => [],
        'NO_SHOW' => [],
        'CANCELLED' => [],
    ];

    public static function allows(EntryStatus $from, EntryStatus $to): bool
    {
        return in_array($to, self::TABLE[$from->value], true);
    }

    /** @return list<EntryStatus> */
    public static function targets(EntryStatus $from): array
    {
        return self::TABLE[$from->value];
    }

    public static function isTerminal(EntryStatus $status): bool
    {
        return self::TABLE[$status->value] === [];
    }
}
