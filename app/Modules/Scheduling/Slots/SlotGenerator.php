<?php

namespace App\Modules\Scheduling\Slots;

use Carbon\CarbonImmutable;

/**
 * The pure slot generator (increment 4.5, architecture.md §8.2). No database,
 * no clock: the same inputs always give the same slots, so it can be tested
 * exhaustively. Loading inputs and writing the projection happen in 4.6.
 *
 * For each local date and each shift effective that date:
 *   window  = shift ∩ clinic opening hours (in person; a location without
 *             opening hours is closed, Phase 4 plan Q9)
 *   holiday = closed: nothing; special hours: window ∩ those hours instead
 *   window -= breaks
 *   window -= blocking exceptions (D-11: closures, leave and unavailability
 *             outrank special hours; a public-holiday block yields only to
 *             special hours at the same place)
 *   cut into [t, t + duration) every duration + buffer, up to the shift's cap
 *   BOTH: the rest of the window (where it fits a visit) becomes walk-in windows
 *   WALK_IN: each part of the window is one walk-in window
 * Special hours add appointment slots for their service, outside the
 * recurring windows of the same mode, and are subject to blocks and closures.
 */
final class SlotGenerator
{
    /**
     * @param  string  $from  first local date, Y-m-d
     * @param  string  $to  last local date, Y-m-d (inclusive)
     * @param  list<ShiftInput>  $shifts
     * @param  array<string, list<array{day: int, opens: string, closes: string, from?: string|null, until?: string|null}>>  $openingHours  by location id
     * @param  list<HolidayInput>  $holidays  per location
     * @param  list<ExceptionInput>  $exceptions
     * @return list<GeneratedSlot>
     */
    public function generate(string $from, string $to, array $shifts, array $openingHours, array $holidays, array $exceptions): array
    {
        $slots = [];
        $recurringByMode = [];

        foreach ($this->dates($from, $to) as $date) {
            $dow = CarbonImmutable::parse($date)->dayOfWeekIso;
            foreach ($shifts as $shift) {
                if ($shift->dayOfWeek !== $dow || $date < $shift->effectiveFrom || ($shift->effectiveUntil !== null && $date > $shift->effectiveUntil)) {
                    continue;
                }
                $window = [[self::at($date, $shift->startsAt, $shift->timezone), self::at($date, $shift->endsAt, $shift->timezone)]];
                if ($shift->locationId !== null) {
                    $window = Intervals::intersect($window, $this->openWindows($shift->locationId, $date, $dow, $shift->timezone, $openingHours, $holidays));
                }
                $window = Intervals::subtract($window, array_map(fn (array $b) => [self::at($date, $b[0], $shift->timezone), self::at($date, $b[1], $shift->timezone)], $shift->breaks));
                $window = Intervals::subtract($window, $this->blocks($shift->locationId, $exceptions));
                $recurringByMode[$shift->modeId] = Intervals::normalize([...($recurringByMode[$shift->modeId] ?? []), ...$window]);

                array_push($slots, ...$this->cut($window, $shift->serviceMode, $shift->durationMinutes, $shift->bufferMinutes, $shift->maxAppointments, $shift->modeId, $shift->id, $shift->locationId, $date));
            }
        }

        foreach ($exceptions as $e) {
            if ($e->effect !== 'ADD_HOURS' || $e->modeId === null) {
                continue;
            }
            $range = [[self::at($from, '00:00', $e->timezone), self::at(CarbonImmutable::parse($to)->addDay()->toDateString(), '00:00', $e->timezone)]];
            $window = Intervals::intersect([[$e->startsAt, $e->endsAt]], $range);
            if ($e->locationId !== null) {
                $window = Intervals::subtract($window, $this->closedDays($e->locationId, $holidays, $e->timezone));
            }
            $window = Intervals::subtract($window, $this->blocks($e->locationId, $exceptions, overriddenBy: $e));
            $window = Intervals::subtract($window, $recurringByMode[$e->modeId] ?? []);
            foreach ($window as $segment) {
                $date = CarbonImmutable::createFromTimestamp($segment[0], $e->timezone)->toDateString();
                array_push($slots, ...$this->cut([$segment], 'APPOINTMENT', $e->durationMinutes, $e->bufferMinutes, null, $e->modeId, null, $e->locationId, $date));
            }
        }

        usort($slots, fn (GeneratedSlot $a, GeneratedSlot $b) => [$a->startsAt, $a->modeId] <=> [$b->startsAt, $b->modeId]);

        return $slots;
    }

    /**
     * @param  list<array{int, int}>  $window
     * @return list<GeneratedSlot>
     */
    private function cut(array $window, string $serviceMode, int $duration, int $buffer, ?int $cap, string $modeId, ?string $shiftId, ?string $locationId, string $date): array
    {
        $out = [];
        $length = $duration * 60;
        $taken = [];
        if ($serviceMode !== 'WALK_IN') {
            $count = 0;
            foreach ($window as [$start, $end]) {
                for ($t = $start; $t + $length <= $end && ($cap === null || $count < $cap); $t += $length + $buffer * 60) {
                    $out[] = new GeneratedSlot($modeId, $shiftId, $locationId, 'APPOINTMENT', $t, $t + $length, $date);
                    $taken[] = [$t, min($end, $t + $length + $buffer * 60)];
                    $count++;
                }
            }
        }
        if ($serviceMode === 'BOTH' || $serviceMode === 'WALK_IN') {
            // Walk-ins never take protected appointment time (§7.6).
            foreach (Intervals::subtract($window, $taken) as [$start, $end]) {
                if ($end - $start >= $length) {
                    $out[] = new GeneratedSlot($modeId, $shiftId, $locationId, 'WALK_IN_WINDOW', $start, $end, $date);
                }
            }
        }

        return $out;
    }

    /**
     * Opening hours of a location on one date, as UTC seconds; a holiday replaces them.
     *
     * @param  array<string, list<array{day: int, opens: string, closes: string, from?: string|null, until?: string|null}>>  $openingHours
     * @param  list<HolidayInput>  $holidays
     * @return list<array{int, int}>
     */
    private function openWindows(string $locationId, string $date, int $dow, string $tz, array $openingHours, array $holidays): array
    {
        foreach ($holidays as $h) {
            if ($h->locationId === $locationId && $h->date === $date) {
                return $h->isClosed || $h->opensAt === null || $h->closesAt === null ? [] : [[self::at($date, $h->opensAt, $tz), self::at($date, $h->closesAt, $tz)]];
            }
        }
        $open = [];
        foreach ($openingHours[$locationId] ?? [] as $row) {
            if ($row['day'] === $dow && ($row['from'] ?? null) <= $date && (($row['until'] ?? null) === null || $date <= $row['until'])) {
                $open[] = [self::at($date, $row['opens'], $tz), self::at($date, $row['closes'], $tz)];
            }
        }

        return Intervals::normalize($open);
    }

    /**
     * @param  list<HolidayInput>  $holidays
     * @return list<array{int, int}>
     */
    private function closedDays(string $locationId, array $holidays, string $tz): array
    {
        $out = [];
        foreach ($holidays as $h) {
            if ($h->locationId === $locationId && $h->isClosed) {
                $out[] = [self::at($h->date, '00:00', $tz), self::at(CarbonImmutable::parse($h->date)->addDay()->toDateString(), '00:00', $tz)];
            }
        }

        return $out;
    }

    /**
     * Blocking exceptions that apply at a place (location-specific ones, and
     * all-places ones). When computing special hours, a public-holiday block
     * at the same place gives way to them (D-11).
     *
     * @param  list<ExceptionInput>  $exceptions
     * @return list<array{int, int}>
     */
    private function blocks(?string $locationId, array $exceptions, ?ExceptionInput $overriddenBy = null): array
    {
        $out = [];
        foreach ($exceptions as $e) {
            if ($e->effect !== 'BLOCK' || ($e->locationId !== null && $e->locationId !== $locationId)) {
                continue;
            }
            if ($overriddenBy !== null && $e->type === 'PUBLIC_HOLIDAY' && $e->locationId === $overriddenBy->locationId) {
                continue;
            }
            $out[] = [$e->startsAt, $e->endsAt];
        }

        return $out;
    }

    /** @return list<string> */
    private function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** A local wall-clock time on a local date, as Unix seconds. */
    private static function at(string $date, string $time, string $tz): int
    {
        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.substr($time, 0, 5), $tz);
        if ($at === null) {
            throw new \InvalidArgumentException("Not a local date and time: {$date} {$time}");
        }

        return $at->getTimestamp();
    }
}
