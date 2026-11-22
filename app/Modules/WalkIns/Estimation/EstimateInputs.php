<?php

namespace App\Modules\WalkIns\Estimation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Everything the estimator needs, gathered by the caller (5.5). Immutable; no database objects. */
final class EstimateInputs
{
    /**
     * @param  list<QueueEntryInput>  $entries  in any order; the estimator applies the queue order itself
     * @param  list<BlockedInterval>  $blockedIntervals  live appointments, ACTIVE holds, scheduled breaks, gaps between shifts
     * @param  CarbonImmutable|null  $closesAt  when walk-ins stop being served today (UTC): the caller passes the earlier of the
     *                                          clinic's closing and the end of the doctor's last shift today; null when unknown
     * @param  string  $timezone  the location's IANA zone
     * @param  string  $localDate  the queue's local date (Y-m-d) in that zone, which is what "today" means; only consulted
     *                             when closesAt is null
     */
    public function __construct(
        public readonly array $entries,
        public readonly ?CurrentConsultationInput $current,
        public readonly array $blockedIntervals,
        public readonly PresenceInput $presence,
        public readonly ?CarbonImmutable $closesAt,
        public readonly string $timezone,
        public readonly string $localDate,
    ) {
        if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Unknown time zone: {$timezone}");
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
            throw new InvalidArgumentException('The local date must be Y-m-d.');
        }
    }
}
