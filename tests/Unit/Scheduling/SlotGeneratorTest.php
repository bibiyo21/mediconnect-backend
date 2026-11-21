<?php

use App\Modules\Scheduling\Slots\ExceptionInput;
use App\Modules\Scheduling\Slots\GeneratedSlot;
use App\Modules\Scheduling\Slots\HolidayInput;
use App\Modules\Scheduling\Slots\ShiftInput;
use App\Modules\Scheduling\Slots\SlotGenerator;
use Carbon\CarbonImmutable;

/*
 * Increment 4.5: the pure slot generator (architecture.md §8.2, D-09, D-11).
 * 2026-12-07 is a Monday. Manila is UTC+8 with no daylight saving.
 */

const MON = '2026-12-07';
const LOC = 'loc-makati';

function shift(array $o = []): ShiftInput
{
    $d = ['id' => 'shift-1', 'dayOfWeek' => 1, 'locationId' => LOC, 'modeId' => 'mode-in', 'serviceMode' => 'APPOINTMENT', 'startsAt' => '09:00', 'endsAt' => '12:00',
        'timezone' => 'Asia/Manila', 'durationMinutes' => 20, 'bufferMinutes' => 5, 'maxAppointments' => null, 'effectiveFrom' => '2026-12-01', 'effectiveUntil' => null, 'breaks' => []];

    return new ShiftInput(...array_merge($d, $o));
}

function open_hours(string $opens = '08:00', string $closes = '17:00', array $days = [1, 2, 3, 4, 5, 6]): array
{
    return [LOC => array_map(fn (int $d) => ['day' => $d, 'opens' => $opens, 'closes' => $closes], $days)];
}

/** @param list<GeneratedSlot> $slots */
function local_times(array $slots, string $kind = 'APPOINTMENT', string $tz = 'Asia/Manila'): array
{
    return array_values(array_map(fn (GeneratedSlot $s) => CarbonImmutable::createFromTimestamp($s->startsAt, $tz)->format('H:i'), array_filter($slots, fn (GeneratedSlot $s) => $s->kind === $kind)));
}

function utc(string $local, string $tz = 'Asia/Manila'): int
{
    return CarbonImmutable::parse($local, $tz)->getTimestamp();
}

function gen(array $shifts, array $hours = [], array $holidays = [], array $exceptions = [], string $from = MON, string $to = MON): array
{
    return (new SlotGenerator)->generate($from, $to, $shifts, $hours, $holidays, $exceptions);
}

it('cuts a shift into slots every duration plus buffer, in the clinic time zone', function () {
    $slots = gen([shift()], open_hours());

    expect(local_times($slots))->toBe(['09:00', '09:25', '09:50', '10:15', '10:40', '11:05', '11:30'])
        ->and($slots[0]->startsAt)->toBe(utc('2026-12-07 09:00'))
        ->and($slots[0]->endsAt - $slots[0]->startsAt)->toBe(20 * 60)
        ->and(CarbonImmutable::createFromTimestamp($slots[0]->startsAt, 'UTC')->format('H:i'))->toBe('01:00')
        ->and($slots[0]->localDate)->toBe(MON)->and($slots[0]->shiftId)->toBe('shift-1');
});

it('skips breaks and stops at the shift cap (D-09)', function () {
    expect(local_times(gen([shift(['breaks' => [['10:30', '10:45']]])], open_hours())))->toBe(['09:00', '09:25', '09:50', '10:45', '11:10', '11:35'])
        ->and(local_times(gen([shift(['maxAppointments' => 4])], open_hours())))->toBe(['09:00', '09:25', '09:50', '10:15']);
});

it('keeps in-person slots inside opening hours, and a location without hours is closed (Q9)', function () {
    expect(local_times(gen([shift()], open_hours('10:00', '17:00'))))->toBe(['10:00', '10:25', '10:50', '11:15', '11:40'])
        ->and(gen([shift()], []))->toBe([])
        ->and(gen([shift(['locationId' => null, 'modeId' => 'mode-online'])], []))->toHaveCount(7); // online needs no clinic hours
});

it('applies clinic holidays: closed days have no slots, special hours replace the opening hours', function () {
    expect(gen([shift()], open_hours(), [new HolidayInput(LOC, MON, true)]))->toBe([])
        ->and(local_times(gen([shift()], open_hours(), [new HolidayInput(LOC, MON, false, '09:00', '10:00')])))->toBe(['09:00', '09:25'])
        ->and(gen([shift()], open_hours(), [new HolidayInput('loc-other', MON, true)]))->toHaveCount(7);
});

it('blocks time by exception: all-places blocks everywhere, located blocks only there', function () {
    $leave = new ExceptionInput('VACATION', 'BLOCK', null, utc('2026-12-07 00:00'), utc('2026-12-08 00:00'));
    $closure = new ExceptionInput('CLINIC_CLOSURE', 'BLOCK', LOC, utc('2026-12-07 10:00'), utc('2026-12-07 11:00'));
    $elsewhere = new ExceptionInput('CLINIC_CLOSURE', 'BLOCK', 'loc-other', utc('2026-12-07 00:00'), utc('2026-12-08 00:00'));
    $online = shift(['locationId' => null, 'modeId' => 'mode-online', 'id' => 'shift-online']);

    expect(gen([shift(), $online], open_hours(), [], [$leave]))->toBe([])
        ->and(local_times(gen([shift()], open_hours(), [], [$closure])))->toBe(['09:00', '09:25', '11:00', '11:25'])
        ->and(gen([shift()], open_hours(), [], [$elsewhere]))->toHaveCount(7)
        ->and(gen([$online], [], [], [$closure]))->toHaveCount(7);
});

it('adds special hours for their service, never duplicating recurring time', function () {
    $saturday = new ExceptionInput('SPECIAL_HOURS', 'ADD_HOURS', LOC, utc('2026-12-12 09:00'), utc('2026-12-12 10:00'), 'mode-in', 'APPOINTMENT', 30, 0);
    $slots = gen([shift()], open_hours(), [], [$saturday], MON, '2026-12-12');
    $sat = array_values(array_filter($slots, fn (GeneratedSlot $s) => $s->localDate === '2026-12-12'));
    expect(local_times($sat))->toBe(['09:00', '09:30'])->and($sat[0]->shiftId)->toBeNull();

    // Overlapping the Monday shift: only the time after it becomes new slots.
    $longer = new ExceptionInput('SPECIAL_HOURS', 'ADD_HOURS', LOC, utc('2026-12-07 11:00'), utc('2026-12-07 13:00'), 'mode-in', 'APPOINTMENT', 30, 0);
    expect(local_times(gen([shift()], open_hours(), [], [$longer])))->toBe(['09:00', '09:25', '09:50', '10:15', '10:40', '11:05', '11:30', '12:00', '12:30']);
});

it('follows the D-11 order: leave and closures outrank special hours; special hours open a public holiday', function () {
    $special = new ExceptionInput('SPECIAL_HOURS', 'ADD_HOURS', LOC, utc('2026-12-07 13:00'), utc('2026-12-07 14:00'), 'mode-in', 'APPOINTMENT', 30, 0);
    $leave = new ExceptionInput('SICK_LEAVE', 'BLOCK', null, utc('2026-12-07 00:00'), utc('2026-12-08 00:00'));
    $holiday = new ExceptionInput('PUBLIC_HOLIDAY', 'BLOCK', LOC, utc('2026-12-07 00:00'), utc('2026-12-08 00:00'));
    $closedClinic = new HolidayInput(LOC, MON, true);

    expect(gen([shift()], open_hours(), [], [$special, $leave]))->toBe([])
        ->and(gen([shift()], open_hours(), [$closedClinic], [$special]))->toBe([])
        // The public holiday closes the recurring shift, but the special hours still open.
        ->and(local_times(gen([shift()], open_hours(), [], [$special, $holiday])))->toBe(['13:00', '13:30']);
});

it('makes walk-in windows from time not protected for appointments (§7.6)', function () {
    $both = gen([shift(['serviceMode' => 'BOTH', 'maxAppointments' => 2])], open_hours());
    expect(local_times($both))->toBe(['09:00', '09:25'])
        ->and(local_times($both, 'WALK_IN_WINDOW'))->toBe(['09:50']);
    $window = array_values(array_filter($both, fn (GeneratedSlot $s) => $s->kind === 'WALK_IN_WINDOW'))[0];
    expect($window->endsAt)->toBe(utc('2026-12-07 12:00'));

    $walkIn = gen([shift(['serviceMode' => 'WALK_IN', 'breaks' => [['10:30', '10:45']]])], open_hours());
    expect(local_times($walkIn))->toBe([])->and(local_times($walkIn, 'WALK_IN_WINDOW'))->toBe(['09:00', '10:45']);
});

it('respects effective dates and weekdays across a range', function () {
    $slots = gen([shift(['effectiveUntil' => '2026-12-14'])], open_hours(), [], [], MON, '2026-12-28');
    expect(array_values(array_unique(array_map(fn (GeneratedSlot $s) => $s->localDate, $slots))))->toBe(['2026-12-07', '2026-12-14']);
});

it('measures real time across a daylight-saving change (future countries)', function () {
    // 2027-03-14 (a Sunday): New York clocks jump from 02:00 to 03:00.
    $ny = shift(['locationId' => null, 'modeId' => 'mode-online', 'dayOfWeek' => 7, 'startsAt' => '01:00', 'endsAt' => '04:00', 'timezone' => 'America/New_York', 'durationMinutes' => 60, 'bufferMinutes' => 0]);
    $slots = gen([$ny], [], [], [], '2027-03-14', '2027-03-14');

    expect($slots)->toHaveCount(2)->and($slots[1]->startsAt - $slots[0]->startsAt)->toBe(3600)
        ->and(local_times($slots, 'APPOINTMENT', 'America/New_York'))->toBe(['01:00', '03:00']);
});

it('never overlaps slots of one mode, never leaves opening hours, and is deterministic', function () {
    $shifts = [
        shift(['breaks' => [['10:00', '10:20']], 'serviceMode' => 'BOTH', 'maxAppointments' => 5]),
        shift(['id' => 'shift-2', 'dayOfWeek' => 3, 'startsAt' => '07:00', 'endsAt' => '18:30', 'durationMinutes' => 15, 'bufferMinutes' => 10]),
        shift(['id' => 'shift-3', 'dayOfWeek' => 1, 'startsAt' => '13:00', 'endsAt' => '16:00', 'durationMinutes' => 45, 'bufferMinutes' => 0]),
    ];
    $exceptions = [new ExceptionInput('TEMPORARY_UNAVAILABILITY', 'BLOCK', LOC, utc('2026-12-09 12:10'), utc('2026-12-09 13:05'))];
    $slots = gen($shifts, open_hours('08:00', '17:00'), [], $exceptions, MON, '2026-12-20');

    foreach (array_values(array_filter($slots, fn (GeneratedSlot $s) => $s->kind === 'APPOINTMENT')) as $i => $s) {
        $local = CarbonImmutable::createFromTimestamp($s->startsAt, 'Asia/Manila');
        $end = CarbonImmutable::createFromTimestamp($s->endsAt, 'Asia/Manila');
        expect($local->format('H:i') >= '08:00')->toBeTrue()->and($end->format('H:i') <= '17:00')->toBeTrue();
    }
    $byMode = [];
    foreach ($slots as $s) {
        $byMode[$s->modeId][] = $s;
    }
    foreach ($byMode as $modeSlots) {
        for ($i = 1; $i < count($modeSlots); $i++) {
            expect($modeSlots[$i]->startsAt >= $modeSlots[$i - 1]->endsAt)->toBeTrue();
        }
    }
    expect(gen($shifts, open_hours('08:00', '17:00'), [], $exceptions, MON, '2026-12-20'))->toEqual($slots);
});
