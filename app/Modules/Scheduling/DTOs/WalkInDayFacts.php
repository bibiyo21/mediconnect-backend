<?php

namespace App\Modules\Scheduling\DTOs;

use Carbon\CarbonImmutable;

/**
 * What the schedule says about one doctor's walk-in day at one location (increment 5.5a, decisions 100 and 101): the capacity
 * and cutoff inputs, and the blocked time the wait estimator needs. All instants are UTC. Read-only facts, derived from
 * PostgreSQL by the scheduling engine; nothing here is stored.
 *
 * Interior blocks inside a shift (a closure or leave that cuts a shift in two) are reflected in the shift's spans only at
 * its edges: the estimator sees gaps between shifts and scheduled breaks, not an exception in the middle of a shift.
 */
final class WalkInDayFacts
{
    /**
     * @param  string  $timezone  the location's IANA zone
     * @param  bool  $clinicClosedToday  the location has no open hours that local day (a closure or no hours)
     * @param  CarbonImmutable|null  $clinicClosesAt  the latest closing of the location's open hours that day
     * @param  bool  $hasWalkInHours  the doctor has an effective walk-in or both-mode shift here that day
     * @param  int  $capacity  sum over those shifts of walkin_capacity, or of the walk-in window minutes divided by the slot length plus buffer
     * @param  CarbonImmutable|null  $lastWindowEndsAt  the end of the last walk-in window (the end of the last shift's hours when there is none)
     * @param  CarbonImmutable|null  $lastShiftEndsAt  the end of the last shift's effective hours
     * @param  list<TimeWindow>  $shiftSpans  merged, ascending: when the doctor works here that day
     * @param  list<TimeWindow>  $offShiftGaps  the gaps between those spans
     * @param  list<TimeWindow>  $scheduledBreaks  breaks written into today's shifts
     * @param  list<TimeWindow>  $appointments  live appointments of the doctor at any place (online included), still to come that day
     * @param  list<TimeWindow>  $activeHolds  ACTIVE, unexpired slot holds of the doctor
     * @param  int|null  $lateGraceMinutes  the late grace in force: the scheduling policy's, else the configured walk-in fallback
     * @param  bool  $doctorActive  the doctor's onboarding status is ACTIVE
     * @param  bool  $locationActive  the location and its clinic are ACTIVE
     */
    public function __construct(
        public readonly string $timezone,
        public readonly bool $clinicClosedToday,
        public readonly ?CarbonImmutable $clinicClosesAt,
        public readonly bool $hasWalkInHours,
        public readonly int $capacity,
        public readonly ?CarbonImmutable $lastWindowEndsAt,
        public readonly ?CarbonImmutable $lastShiftEndsAt,
        public readonly array $shiftSpans,
        public readonly array $offShiftGaps,
        public readonly array $scheduledBreaks,
        public readonly array $appointments,
        public readonly array $activeHolds,
        public readonly ?int $lateGraceMinutes,
        public readonly bool $doctorActive = true,
        public readonly bool $locationActive = true,
    ) {}

    /** When walk-ins stop being served today: the earlier of the clinic's closing and the end of the doctor's last shift. */
    public function closesAt(): ?CarbonImmutable
    {
        $candidates = array_values(array_filter([$this->clinicClosesAt, $this->lastShiftEndsAt]));
        if ($candidates === []) {
            return null;
        }

        return array_reduce($candidates, fn (?CarbonImmutable $c, CarbonImmutable $x) => $c === null || $x < $c ? $x : $c);
    }

    /** The start of the first working span after $now, or null. */
    public function nextShiftStartAfter(CarbonImmutable $now): ?CarbonImmutable
    {
        foreach ($this->shiftSpans as $span) {
            if ($span->startsAt > $now) {
                return $span->startsAt;
            }
        }

        return null;
    }

    /** Whether $now falls inside one of the doctor's working spans: a doctor not in then is late, not "off until the next shift". */
    public function inShiftAt(CarbonImmutable $now): bool
    {
        foreach ($this->shiftSpans as $span) {
            if ($span->startsAt <= $now && $now < $span->endsAt) {
                return true;
            }
        }

        return false;
    }
}
