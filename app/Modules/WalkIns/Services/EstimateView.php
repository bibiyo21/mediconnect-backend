<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\WalkIns\Estimation\WaitBasis;
use App\Modules\WalkIns\Estimation\WaitEstimate;

/**
 * What a client is told about a wait. An estimate, never a promise (CLAUDE.md §9.4): always a range or nothing, always
 * is_estimate. The patient view withholds minutes when the basis includes the default duration (E3: the wait is a guess about
 * a guess) or the wait is unknown, and never carries the basis codes (walk-in-management §4 rule 7). The staff view carries
 * them, and minutes only where the caller asks for them (the board shows minutes for the next few waiting entries).
 */
final class EstimateView
{
    public const ESTIMATED = 'ESTIMATED';

    public const NOT_AVAILABLE_YET = 'NOT_AVAILABLE_YET';

    public const UNKNOWN = 'UNKNOWN';

    /** @return array{position: int, min_minutes: int|null, max_minutes: int|null, is_estimate: true, may_not_be_served_today: bool|null, wait_status: string} */
    public static function forPatient(WaitEstimate $e): array
    {
        $status = self::waitStatus($e);
        $show = $status === self::ESTIMATED;

        return [
            'position' => $e->position,
            'min_minutes' => $show ? $e->minMinutes : null,
            'max_minutes' => $show ? $e->maxMinutes : null,
            'is_estimate' => true,
            'may_not_be_served_today' => $e->mayNotBeServedToday,
            'wait_status' => $status,
        ];
    }

    /** @return array{position: int, min_minutes: int|null, max_minutes: int|null, is_estimate: true, may_not_be_served_today: bool|null, wait_status: string, basis: list<string>} */
    public static function forStaff(WaitEstimate $e, bool $withMinutes): array
    {
        $status = self::waitStatus($e);

        return [
            'position' => $e->position,
            'min_minutes' => $withMinutes ? $e->minMinutes : null,
            'max_minutes' => $withMinutes ? $e->maxMinutes : null,
            'is_estimate' => true,
            'may_not_be_served_today' => $e->mayNotBeServedToday,
            'wait_status' => $withMinutes || $status === self::UNKNOWN ? $status : self::NOT_AVAILABLE_YET,
            'basis' => array_map(fn (WaitBasis $b) => $b->value, $e->basis),
        ];
    }

    public static function waitStatus(WaitEstimate $e): string
    {
        if ($e->minMinutes === null || $e->maxMinutes === null || in_array(WaitBasis::Unknown, $e->basis, true)) {
            return self::UNKNOWN;
        }

        return in_array(WaitBasis::DurationDefault, $e->basis, true) ? self::NOT_AVAILABLE_YET : self::ESTIMATED;
    }
}
