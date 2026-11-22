<?php

namespace App\Modules\WalkIns\Estimation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Time the doctor cannot give a walk-in, [startsAt, endsAt) in UTC. The caller (5.5) passes:
 *  - live appointments and ACTIVE holds as Appointment and Hold;
 *  - scheduled breaks of today's shifts as ScheduledBreak;
 *  - the gaps between today's shifts at the location as OffShift.
 * Time after the doctor's last shift is not an interval: it is the estimator's closesAt, which the caller
 * sets to the earlier of the clinic's closing and the end of the doctor's last shift today.
 */
final class BlockedInterval
{
    public function __construct(
        public readonly BlockKind $kind,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {
        if ($endsAt->getTimestamp() <= $startsAt->getTimestamp()) {
            throw new InvalidArgumentException('A blocked interval must end after it starts.');
        }
    }
}
