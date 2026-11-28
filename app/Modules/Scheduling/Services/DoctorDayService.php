<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\DoctorDay;
use App\Modules\Scheduling\DTOs\DoctorDayShift;
use App\Modules\Scheduling\DTOs\TimeWindow;
use App\Modules\Scheduling\Slots\AvailabilityInputs;
use App\Modules\Scheduling\Slots\ShiftInput;
use App\Modules\Scheduling\Slots\SlotGenerator;
use Carbon\CarbonImmutable;

/**
 * Every in-person shift of one doctor at one location on one local date, whatever its service mode (increment 5.9-B, M2). The effective hours
 * come from the slot generator, so exceptions and special hours are already applied. One doctor and one date per call.
 */
final class DoctorDayService implements DoctorDay
{
    public function __construct(
        private readonly AvailabilityInputs $inputs,
        private readonly SlotGenerator $generator,
    ) {}

    public function forDoctorAtLocation(string $doctorId, string $locationId, string $localDate): array
    {
        $in = $this->inputs->load($doctorId, $localDate, $localDate);
        /** @var list<ShiftInput> $shifts */
        $shifts = array_values(array_filter($in['shifts'], fn (ShiftInput $s) => $s->locationId === $locationId));
        if ($shifts === []) {
            return [];
        }
        // The shift's working span per date, before its breaks: its hours after exceptions and special hours (a shift with none is absent).
        $windows = $this->generator->shiftWindows($localDate, $localDate, $shifts, $in['hours'], $in['holidays'], $in['exceptions']);

        $out = [];
        foreach ($shifts as $shift) {
            if (! isset($windows[$shift->id])) {
                continue; // no effective hours that day: a closure, leave, or special hours that do not reach it
            }
            $from = $windows[$shift->id][0][0];
            $until = $windows[$shift->id][0][1];
            foreach ($windows[$shift->id] as [$start, $end]) {
                $from = min($from, $start);
                $until = max($until, $end);
            }

            $breaks = [];
            foreach ($shift->breaks as $i => [$start, $end]) {
                $breakId = $shift->breakIds[$i] ?? '';
                if ($breakId === '') {
                    continue; // an unknown id cannot be referred to by a caller
                }
                $breakFrom = $this->at($localDate, $start, $shift->timezone);
                $breakUntil = $this->at($localDate, $end, $shift->timezone);
                if ($breakFrom < $from || $breakUntil > $until) {
                    continue; // special hours clipped the shift: a break outside its effective hours is not listed
                }
                $breaks[] = new TimeWindow(CarbonImmutable::createFromTimestampUTC($breakFrom), CarbonImmutable::createFromTimestampUTC($breakUntil), $breakId);
            }

            $out[] = new DoctorDayShift($shift->id, $shift->serviceMode, CarbonImmutable::createFromTimestampUTC($from), CarbonImmutable::createFromTimestampUTC($until), $breaks);
        }

        usort($out, fn (DoctorDayShift $a, DoctorDayShift $b) => $a->startsAt <=> $b->startsAt);

        return $out;
    }

    private function at(string $date, string $time, string $timezone): int
    {
        return CarbonImmutable::parse($date.' '.substr($time, 0, 5), $timezone)->getTimestamp();
    }
}
