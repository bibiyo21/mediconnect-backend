<?php

use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Estimation\BlockedInterval;
use App\Modules\WalkIns\Estimation\BlockKind;
use App\Modules\WalkIns\Estimation\CurrentConsultationInput;
use App\Modules\WalkIns\Estimation\DurationSource;
use App\Modules\WalkIns\Estimation\EstimateInputs;
use App\Modules\WalkIns\Estimation\EstimatorSettings;
use App\Modules\WalkIns\Estimation\PresenceInput;
use App\Modules\WalkIns\Estimation\QueueEntryInput;
use App\Modules\WalkIns\Estimation\RollingDurationStats;
use App\Modules\WalkIns\Estimation\WaitBasis;
use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Estimation\WaitTimeEstimator;
use Carbon\CarbonImmutable;

/*
 * Increment 5.4: the pure wait-time estimator (architecture.md §10.4, CLAUDE.md §9.4). No database.
 * Default settings: each consultation duration runs from 75% to 125%; a fixed return time (break end, shift
 * start) is never counted earlier and the high end allows lateness of 25% of the time away, between 5 and 30
 * minutes; no estimate is narrower than 5 minutes (even a patient who is next in line: 0 to 5).
 * 2026-12-07 is a Monday; Manila is UTC+8 all year. Entries default to a 10 minute consultation.
 */

function est_local(string $time, string $date = '2026-12-07', string $zone = 'Asia/Manila'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", $zone)->utc();
}

function est_entry(int $number, int $triage = 0, EntryStatus $status = EntryStatus::Waiting, int $minutes = 10, DurationSource $source = DurationSource::Service): QueueEntryInput
{
    return new QueueEntryInput("e{$number}", $number, $triage, $status, $minutes * 60, $source);
}

function est_block(BlockKind $kind, string $from, string $to): BlockedInterval
{
    return new BlockedInterval($kind, est_local($from), est_local($to));
}

function est_current(string $startedAt, int $minutes, DurationSource $source = DurationSource::Service): CurrentConsultationInput
{
    return new CurrentConsultationInput(est_local($startedAt), $minutes * 60, $source);
}

/** @param  list<QueueEntryInput>  $entries */
function est_inputs(array $entries = [], array $o = []): EstimateInputs
{
    return new EstimateInputs(
        $entries,
        $o['current'] ?? null,
        $o['blocked'] ?? [],
        $o['presence'] ?? new PresenceInput(PresenceStatus::In),
        array_key_exists('closes', $o) ? $o['closes'] : est_local('17:00'),
        $o['zone'] ?? 'Asia/Manila',
        $o['date'] ?? '2026-12-07',
    );
}

function est_run(EstimateInputs $inputs, string $now = '10:00', string $date = '2026-12-07', string $zone = 'Asia/Manila'): array
{
    return (new WaitTimeEstimator(new PinnedClock(est_local($now, $date, $zone))))->estimate($inputs);
}

function est_codes(WaitEstimate $e): array
{
    return array_map(fn (WaitBasis $b) => $b->value, $e->basis);
}

function est_range(WaitEstimate $e): array
{
    return [$e->minMinutes, $e->maxMinutes];
}

it('gives a patient who joins an empty queue a short range, never a bare zero, still flagged as an estimate', function () {
    $estimator = new WaitTimeEstimator(new PinnedClock(est_local('10:00')));

    expect($estimator->estimate(est_inputs()))->toBe([]);

    $e = $estimator->estimateForNewEntry(est_inputs(), 600, DurationSource::Service);
    expect([...est_range($e), $e->mayNotBeServedToday, $e->position])->toBe([0, 5, false, 1])
        ->and($e->toArray()['is_estimate'])->toBeTrue()
        ->and(est_codes($e))->toBe(['next_in_line', 'duration_service']);
});

it('puts a new entry with higher triage priority ahead of the ordinary queue', function () {
    $estimator = new WaitTimeEstimator(new PinnedClock(est_local('10:00')));
    $queue = est_inputs([est_entry(1), est_entry(2)]);

    $urgent = $estimator->estimateForNewEntry($queue, 600, DurationSource::Rolling, triagePriority: 5);
    $ordinary = $estimator->estimateForNewEntry($queue, 600, DurationSource::Rolling);

    expect([$urgent->position, ...est_range($urgent)])->toBe([1, 0, 5])
        ->and(est_codes($urgent))->toContain('next_in_line', 'triage_reordered')
        ->and([$ordinary->position, ...est_range($ordinary)])->toBe([3, 15, 25])
        ->and(est_codes($ordinary))->not->toContain('triage_reordered');
});

it('counts the rest of the current consultation and always returns a range', function () {
    // Started 10:00, expected 20 minutes, now 10:10: 10 minutes left, so 7.5 to 12.5 minutes.
    $r = est_run(est_inputs([est_entry(1)], ['current' => est_current('10:00', 20, DurationSource::Rolling)]), '10:10');

    expect(est_range($r['e1']))->toBe([7, 13])
        ->and(est_codes($r['e1']))->toBe(['current_consultation', 'duration_rolling', 'duration_service']);
});

it('floors the remaining time at zero when the current consultation overruns, and keeps a range', function () {
    // Expected 20 minutes, 35 minutes in: it may end any moment, or run a little longer (a quarter of its length).
    $r = est_run(est_inputs([est_entry(1)], ['current' => est_current('10:00', 20)]), '10:35');

    expect(est_range($r['e1']))->toBe([0, 5])
        ->and(est_codes($r['e1']))->toContain('consultation_overrun')->not->toContain('current_consultation');
});

it('serves higher triage priority first, then the lowest number, and says so', function () {
    $r = est_run(est_inputs([est_entry(1), est_entry(2), est_entry(3, triage: 5)]));

    expect(array_keys($r))->toBe(['e3', 'e1', 'e2'])
        ->and(array_map(fn ($e) => $e->position, $r))->toBe(['e3' => 1, 'e1' => 2, 'e2' => 3])
        ->and(est_range($r['e3']))->toBe([0, 5])
        ->and(est_range($r['e1']))->toBe([7, 13])   // one 10 minute visit ahead: 7.5 to 12.5
        ->and(est_range($r['e2']))->toBe([15, 25])  // two ahead: 15 to 25
        ->and(est_codes($r['e1']))->toContain('triage_reordered', 'queue_ahead')
        ->and(est_codes($r['e3']))->toContain('triage_reordered');
});

it('does not mark a plain first-come queue as reordered', function () {
    $r = est_run(est_inputs([est_entry(2), est_entry(1)]));

    expect(array_keys($r))->toBe(['e1', 'e2'])->and(est_codes($r['e2']))->not->toContain('triage_reordered');
});

it('puts called patients ahead of everyone, ordered among themselves by priority then number', function () {
    $r = est_run(est_inputs([est_entry(1, triage: 3), est_entry(5, status: EntryStatus::Called), est_entry(2, triage: 2, status: EntryStatus::Called), est_entry(4, status: EntryStatus::Called)]));

    expect(array_keys($r))->toBe(['e2', 'e4', 'e5', 'e1']);
});

it('ignores entries that are not waiting any more and flags unarrived ones that are counted ahead', function () {
    $r = est_run(est_inputs([
        est_entry(1, status: EntryStatus::Completed), est_entry(2, status: EntryStatus::NoShow), est_entry(3, status: EntryStatus::Skipped),
        est_entry(4, status: EntryStatus::InConsultation), est_entry(5, status: EntryStatus::Cancelled),
        est_entry(6, status: EntryStatus::Registered), est_entry(7),
    ]));

    expect(array_keys($r))->toBe(['e6', 'e7'])
        ->and(est_codes($r['e7']))->toContain('unarrived_counted')
        ->and(est_codes($r['e6']))->not->toContain('unarrived_counted');
});

it('never counts a break end earlier than announced, and allows lateness only on the high end', function () {
    // Break ends 10:30, 30 minutes away: lateness 25% = 7.5 minutes (within 5 to 30), so the doctor is back 10:30 to 10:37:30.
    $r = est_run(est_inputs([est_entry(1), est_entry(2)], ['presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('10:30'))]));

    // First patient: 30 to 37.5. The second adds one 10 minute visit: 30 + 7.5 = 37.5 low, 37.5 + 12.5 = 50 high.
    expect(est_range($r['e1']))->toBe([30, 38])
        ->and(est_range($r['e2']))->toBe([37, 50])
        ->and(est_codes($r['e1']))->toBe(['doctor_break', 'duration_service']);
});

it('caps the lateness allowed on a long fixed return time', function () {
    // Away 5 hours: 25% would be 75 minutes, the cap is 30.
    $r = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::NotCheckedIn, est_local('15:00'))]));

    expect(est_range($r['e1']))->toBe([300, 330]);
});

it('allows a margin when a break has run past its expected end, and gives up when no end is known', function () {
    $overran = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('09:50'))]));
    expect(est_range($overran['e1']))->toBe([0, 5])
        ->and(est_codes($overran['e1']))->toContain('doctor_break', 'break_overrun');

    $open = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::OnBreak)]));
    expect(est_range($open['e1']))->toBe([null, null])
        ->and(est_codes($open['e1']))->toBe(['doctor_break', 'unknown'])
        ->and($open['e1']->callAtMin)->toBeNull();
});

it('keeps the break overrun margin once for several patients, not once each', function () {
    $r = est_run(est_inputs([est_entry(1), est_entry(2)], ['presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('09:50'))]));

    // Low: back now, e2 waits one short visit (7.5). High: back within 5 minutes, then one long visit (12.5): 17.5.
    expect(est_range($r['e1']))->toBe([0, 5])->and(est_range($r['e2']))->toBe([7, 18]);
});

it('treats a temporarily unavailable doctor like a break with a known or unknown return', function () {
    // Back at 10:20 (20 minutes away): lateness 25% = 5 minutes.
    $known = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::TemporarilyUnavailable, est_local('10:20'))]));
    $unknown = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::TemporarilyUnavailable)]));

    expect(est_range($known['e1']))->toBe([20, 25])
        ->and(est_codes($known['e1']))->toContain('doctor_unavailable')
        ->and(est_range($unknown['e1']))->toBe([null, null]);
});

it('waits for the shift start when the doctor has not checked in, and says unknown when there is none', function () {
    // Shift starts in 2 hours: lateness 25% = 30 minutes (the cap), never earlier than the start.
    $withShift = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::NotCheckedIn, est_local('12:00'))]));
    expect([...est_range($withShift['e1']), $withShift['e1']->mayNotBeServedToday])->toBe([120, 150, false])
        ->and(est_codes($withShift['e1']))->toContain('doctor_not_checked_in')->not->toContain('unknown');

    $noShift = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::NotCheckedIn)]));
    expect(est_range($noShift['e1']))->toBe([null, null])
        ->and(est_codes($noShift['e1']))->toBe(['doctor_not_checked_in', 'unknown']);

    // A shift that should already have started with nobody there is unknown too, never "now".
    $late = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::NotCheckedIn, est_local('09:00'))]));
    expect($late['e1']->minMinutes)->toBeNull();

    $out = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::Out, est_local('12:00'))]));
    expect(est_codes($out['e1']))->toContain('doctor_checked_out')->and(est_range($out['e1']))->toBe([120, 150]);
});

it('lets a protected appointment push the next consultation past it when the visit would not fit before', function () {
    // 10:05 to 10:30 is protected. A 15 minute visit (11.25 to 18.75 minutes) cannot finish before 10:05 in either case, so it starts at 10:30.
    $r = est_run(est_inputs([est_entry(1, minutes: 15)], ['blocked' => [est_block(BlockKind::Appointment, '10:05', '10:30')]]));

    expect(est_range($r['e1']))->toBe([30, 35])->and(est_codes($r['e1']))->toContain('protected_appointment');
});

it('uses the gap before a protected appointment only when the visit fits, per scenario', function () {
    // Appointment 10:30 to 10:50. Visits are 15 minutes: 11.25 (short) or 18.75 (long).
    $blocked = [est_block(BlockKind::Appointment, '10:30', '10:50')];
    $r = est_run(est_inputs([est_entry(1, minutes: 15), est_entry(2, minutes: 15)], ['blocked' => $blocked]));

    // First patient is served at once. The second starts at 10:11:15 when visits run short (it ends 10:22:30, before 10:30),
    // but at 10:50 when they run long (10:18:45 + 18.75 minutes reaches 10:37:30, into the appointment).
    expect(est_range($r['e1']))->toBe([0, 5])
        ->and(est_range($r['e2']))->toBe([11, 50])
        ->and(est_codes($r['e2']))->toContain('protected_appointment')
        ->and(est_codes($r['e1']))->not->toContain('protected_appointment');
});

it('ignores blocked time that has already passed or falls after the patient is seen', function () {
    $blocked = [est_block(BlockKind::Appointment, '08:00', '09:00'), est_block(BlockKind::Appointment, '15:00', '15:30')];
    $r = est_run(est_inputs([est_entry(1)], ['blocked' => $blocked]));

    expect(est_range($r['e1']))->toBe([0, 5])->and(est_codes($r['e1']))->not->toContain('protected_appointment');
});

it('waits out a protected appointment that is already under way', function () {
    $r = est_run(est_inputs([est_entry(1)], ['blocked' => [est_block(BlockKind::Appointment, '09:50', '10:25')]]));

    expect(est_range($r['e1']))->toBe([25, 30])->and(est_codes($r['e1']))->toContain('protected_appointment');
});

it('counts time only once when a protected appointment overlaps a break', function () {
    // Break to 10:30 and an appointment 10:15 to 10:45 are one blocked stretch, 10:00 to 10:45: a 45 minute wait, not 60.
    $r = est_run(est_inputs([est_entry(1)], [
        'presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('10:30')),
        'blocked' => [est_block(BlockKind::Appointment, '10:15', '10:45')],
    ]));

    expect(est_range($r['e1']))->toBe([45, 50])->and(est_codes($r['e1']))->toContain('doctor_break', 'protected_appointment');
});

it('counts time only once when the current consultation overlaps a protected appointment', function () {
    // Consultation ends 10:20 (10:17:30 to 10:22:30 allowing for pace); the appointment starts 10:12 and ends 10:30. One wait to 10:30.
    $r = est_run(est_inputs([est_entry(1)], [
        'current' => est_current('10:00', 20),
        'blocked' => [est_block(BlockKind::Appointment, '10:12', '10:30')],
    ]), '10:10');

    expect(est_range($r['e1']))->toBe([20, 25])->and(est_codes($r['e1']))->toContain('current_consultation', 'protected_appointment');
});

it('treats touching blocked intervals as one stretch', function () {
    // A break that ends exactly when an appointment starts: 10:00 to 10:50 with no gap to slip a visit into.
    $afterBreak = est_run(est_inputs([est_entry(1)], [
        'presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('10:30')),
        'blocked' => [est_block(BlockKind::Appointment, '10:30', '10:50')],
    ]));
    expect(est_range($afterBreak['e1']))->toBe([50, 55]);

    // Two back-to-back bookings, one a hold: each keeps its own basis code.
    $backToBack = est_run(est_inputs([est_entry(1)], ['blocked' => [est_block(BlockKind::Hold, '10:00', '10:20'), est_block(BlockKind::Appointment, '10:20', '10:40')]]));
    expect(est_range($backToBack['e1']))->toBe([40, 45])->and(est_codes($backToBack['e1']))->toContain('protected_appointment', 'protected_hold');
});

it('works around a scheduled break before the patient\'s turn', function () {
    // Lunch 12:00 to 13:00. Three 20 minute visits from 11:30 (15 to 25 minutes each).
    $blocked = [est_block(BlockKind::ScheduledBreak, '12:00', '13:00')];
    $r = est_run(est_inputs([est_entry(1, minutes: 20), est_entry(2, minutes: 20), est_entry(3, minutes: 20)], ['blocked' => $blocked]), '11:30');

    // e2: short case starts 11:45 and ends exactly 12:00 (fits); long case starts 11:55 and would end 12:20, so waits for 13:00.
    // e3: short case waits for 13:00; long case starts at 13:25.
    expect(est_range($r['e1']))->toBe([0, 5])
        ->and(est_range($r['e2']))->toBe([15, 90])
        ->and(est_range($r['e3']))->toBe([90, 115])
        ->and(est_codes($r['e2']))->toContain('scheduled_break')
        ->and(est_codes($r['e1']))->not->toContain('scheduled_break');
});

it('waits out a gap between shifts', function () {
    $blocked = [est_block(BlockKind::OffShift, '12:00', '14:00')];
    $r = est_run(est_inputs([est_entry(1)], ['blocked' => $blocked, 'current' => est_current('11:30', 30)]), '11:40');

    // 20 minutes of consultation left (15 to 25 minutes): the visit would run into the gap, so it starts at 14:00.
    expect(est_range($r['e1']))->toBe([140, 145])->and(est_codes($r['e1']))->toContain('off_shift');
});

it('flags a patient who may not be seen before the doctor\'s shift ends even when the clinic stays open', function () {
    // Morning shift ends 12:00 and the clinic closes 17:00; the caller passes the earlier as closesAt.
    $closes = est_local('12:00');
    $current = est_current('11:30', 30); // at 11:40, 20 minutes left: 15 to 25 minutes

    $past = est_run(est_inputs([est_entry(1), est_entry(2)], ['current' => $current, 'closes' => $closes]), '11:40');
    expect([...est_range($past['e1']), $past['e1']->mayNotBeServedToday])->toBe([15, 25, true])   // 11:40 + 25 = 12:05
        ->and([...est_range($past['e2']), $past['e2']->mayNotBeServedToday])->toBe([22, 38, true])
        ->and(est_codes($past['e1']))->toContain('clinic_closing');

    $before = est_run(est_inputs([est_entry(1)], ['current' => est_current('11:00', 30), 'closes' => $closes]), '11:20');
    expect([...est_range($before['e1']), $before['e1']->mayNotBeServedToday])->toBe([7, 13, false]);
});

it('decides "not served today" by the closing time, to the second, when it is known', function () {
    // 10:10, 20 minutes left: the range is 15 to 25 minutes, so the high end is 10:35:00.
    $run = fn (CarbonImmutable $closes) => est_run(est_inputs([est_entry(1)], ['current' => est_current('10:00', 30), 'closes' => $closes]), '10:10')['e1'];

    expect($run(est_local('10:35')->subSecond())->mayNotBeServedToday)->toBeTrue()
        ->and($run(est_local('10:35'))->mayNotBeServedToday)->toBeTrue()          // closing exactly at the high end
        ->and($run(est_local('10:35')->addSecond())->mayNotBeServedToday)->toBeFalse();
});

it('flags a patient who may not be seen before closing, and one already past closing', function () {
    $r = est_run(est_inputs([est_entry(1)], ['current' => est_current('16:30', 30)]), '16:40');
    // 20 minutes left: 15 to 25 minutes, so the high end reaches 17:05.
    expect([...est_range($r['e1']), $r['e1']->mayNotBeServedToday])->toBe([15, 25, true])->and(est_codes($r['e1']))->toContain('clinic_closing');

    $fits = est_run(est_inputs([est_entry(1)], ['current' => est_current('16:00', 30)]), '16:20');
    expect($fits['e1']->mayNotBeServedToday)->toBeFalse();

    $after = est_run(est_inputs([est_entry(1)]), '17:10');
    expect([...est_range($after['e1']), $after['e1']->mayNotBeServedToday])->toBe([0, 5, true]);

    $afterUnknown = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::NotCheckedIn)]), '17:10');
    expect([...est_range($afterUnknown['e1']), $afterUnknown['e1']->mayNotBeServedToday])->toBe([null, null, true]);

    $noClosing = est_run(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::NotCheckedIn)]));
    expect($noClosing['e1']->mayNotBeServedToday)->toBeNull();
});

it('lets a clinic that closes after midnight serve past midnight, and uses the calendar day only without a closing time', function () {
    // The queue is Monday 7 December. It is 23:50 and the clinic closes 02:00 Tuesday; the high end is 00:15 Tuesday.
    $current = est_current('23:40', 30);
    $closes = est_local('02:00', '2026-12-08');

    $open = est_run(est_inputs([est_entry(1)], ['current' => $current, 'closes' => $closes]), '23:50');
    expect([...est_range($open['e1']), $open['e1']->mayNotBeServedToday])->toBe([15, 25, false]);

    $noClosing = est_run(est_inputs([est_entry(1)], ['current' => $current, 'closes' => null]), '23:50');
    expect($noClosing['e1']->mayNotBeServedToday)->toBeTrue();
});

it('works in Manila time, where tomorrow is another calendar day', function () {
    // 20:00 Monday; shift starts 09:00 Tuesday: 13 hours, plus up to 30 minutes late. No closing time known, so only the date says "not today".
    $r = est_run(est_inputs([est_entry(1)], ['closes' => null, 'presence' => new PresenceInput(PresenceStatus::NotCheckedIn, est_local('09:00', '2026-12-08'))]), '20:00');

    expect([...est_range($r['e1']), $r['e1']->mayNotBeServedToday])->toBe([780, 810, true])
        ->and($r['e1']->callAtMin?->toIso8601ZuluString())->toBe(est_local('20:00')->addMinutes(780)->toIso8601ZuluString());
});

it('measures real elapsed time across the 8 March 2026 New York daylight saving change', function () {
    $zone = 'America/New_York';
    // Saturday 20:00 EST to Sunday 09:00 EDT is 12 real hours (the clock skips 02:00 to 03:00): 720 minutes, not 780.
    $shiftStart = est_local('09:00', '2026-03-08', $zone);
    $r = est_run(est_inputs([est_entry(1)], ['closes' => null, 'zone' => $zone, 'date' => '2026-03-07', 'presence' => new PresenceInput(PresenceStatus::NotCheckedIn, $shiftStart)]), '20:00', '2026-03-07', $zone);

    expect([...est_range($r['e1']), $r['e1']->mayNotBeServedToday])->toBe([720, 750, true]);

    // Sunday 08:00 EDT with the doctor in and the clinic closing at 17:00 EDT (21:00 UTC, not 22:00): served today.
    $sunday = est_run(est_inputs([est_entry(1)], ['closes' => est_local('17:00', '2026-03-08', $zone), 'zone' => $zone, 'date' => '2026-03-08']), '08:00', '2026-03-08', $zone);
    expect([...est_range($sunday['e1']), $sunday['e1']->mayNotBeServedToday])->toBe([0, 5, false])
        ->and(est_local('17:00', '2026-03-08', $zone)->toIso8601ZuluString())->toBe('2026-03-08T21:00:00Z');

    // The same Sunday, 16:40 EDT, 20 minutes of consultation left: the high end reaches 17:05 EDT, past closing.
    $late = est_run(est_inputs([est_entry(1)], ['closes' => est_local('17:00', '2026-03-08', $zone), 'zone' => $zone, 'date' => '2026-03-08',
        'current' => new CurrentConsultationInput(est_local('16:30', '2026-03-08', $zone), 1800, DurationSource::Service)]), '16:40', '2026-03-08', $zone);
    expect($late['e1']->mayNotBeServedToday)->toBeTrue();
});

it('is deterministic whatever order the entries arrive in', function () {
    $entries = [est_entry(3, triage: 2), est_entry(1), est_entry(2, status: EntryStatus::Registered), est_entry(4, triage: 2)];
    $options = ['current' => est_current('09:55', 15, DurationSource::Rolling), 'blocked' => [est_block(BlockKind::Appointment, '10:20', '10:40')]];

    $first = est_run(est_inputs($entries, $options));
    $again = est_run(est_inputs($entries, $options));
    $shuffled = est_run(est_inputs(array_reverse($entries), $options));

    expect($again)->toEqual($first)->and($shuffled)->toEqual($first)
        ->and(array_map(fn ($e) => $e->toArray(), $first))->toEqual(array_map(fn ($e) => $e->toArray(), $shuffled));
});

it('never collapses a wait into a single number, not even a zero wait', function ($make, string $now) {
    $estimates = est_run($make instanceof Closure ? $make() : $make, $now);
    expect($estimates)->not->toBe([]);
    foreach ($estimates as $e) {
        $a = $e->toArray();
        expect($a['is_estimate'])->toBeTrue();
        if ($a['min_minutes'] === null) {
            expect($a['max_minutes'])->toBeNull();

            continue;
        }
        expect($a['min_minutes'])->toBeGreaterThanOrEqual(0)->and($a['max_minutes'])->toBeGreaterThan($a['min_minutes']);
    }
})->with(function () {
    $block = [est_block(BlockKind::Appointment, '10:00', '10:40')];

    return [
        'empty doctor in' => [fn () => est_inputs([est_entry(1)]), '10:00'],
        'wait is only a protected appointment' => [fn () => est_inputs([est_entry(1)], ['blocked' => $block]), '10:00'],
        'wait is only a break' => [fn () => est_inputs([est_entry(1), est_entry(2)], ['presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('10:30'))]), '10:00'],
        'overrun' => [fn () => est_inputs([est_entry(1)], ['current' => est_current('09:00', 10, DurationSource::Default)]), '10:00'],
        'tiny wait' => [fn () => est_inputs([est_entry(1)], ['current' => new CurrentConsultationInput(est_local('09:55'), 301, DurationSource::Default)]), '10:00'],
        'unknown' => [fn () => est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::Out)]), '10:00'],
    ];
});

it('rejects out-of-range inputs and settings', function () {
    expect(fn () => new QueueEntryInput('x', 1, 10, EntryStatus::Waiting, 600, DurationSource::Default))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new QueueEntryInput('x', 0, 0, EntryStatus::Waiting, 600, DurationSource::Default))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new QueueEntryInput('x', 1, 0, EntryStatus::Waiting, 0, DurationSource::Default))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new QueueEntryInput('x', 1, 0, EntryStatus::Waiting, 86401, DurationSource::Default))->toThrow(InvalidArgumentException::class)
        ->and((new QueueEntryInput('x', 1, 0, EntryStatus::Waiting, 86400, DurationSource::Default))->expectedDurationSeconds)->toBe(86400)
        ->and(fn () => new CurrentConsultationInput(est_local('10:00'), 86401, DurationSource::Default))->toThrow(InvalidArgumentException::class)
        ->and(fn () => est_block(BlockKind::Hold, '10:00', '10:00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EstimatorSettings(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EstimatorSettings(25, 10, 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => est_inputs([], ['zone' => 'Mars/Olympus']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => est_inputs([], ['date' => '07/12/2026']))->toThrow(InvalidArgumentException::class);
});

it('uses the configured spread, minimum width and lateness cap', function () {
    $estimator = new WaitTimeEstimator(new PinnedClock(est_local('10:00')), new EstimatorSettings(spreadPercent: 50, minRangeWidthMinutes: 2, maxLatenessMinutes: 10));
    $r = $estimator->estimate(est_inputs([est_entry(1), est_entry(2, minutes: 20)]));

    // One 10 minute visit ahead: 5 to 15 minutes.
    expect(est_range($r['e2']))->toBe([5, 15]);

    $away = $estimator->estimate(est_inputs([est_entry(1)], ['presence' => new PresenceInput(PresenceStatus::OnBreak, est_local('11:00'))]));
    // 60 minutes away: 50% would be 30 minutes, the cap is 10.
    expect(est_range($away['e1']))->toBe([60, 70]);
});

it('takes the median of few samples and a trimmed mean of many', function () {
    expect(RollingDurationStats::typicalSeconds([600, 600, 600, 600, 600, 600, 600, 600, 60, 7200], 0.1))->toBe(600)
        ->and(RollingDurationStats::typicalSeconds([100, 200], 0.49))->toBe(150)
        ->and(RollingDurationStats::typicalSeconds([300], 0.1))->toBe(300)
        ->and(RollingDurationStats::typicalSeconds([100, 500, 9000], 0.1))->toBe(500)       // median, not the skewed mean
        ->and(RollingDurationStats::typicalSeconds([100, 100, 5000, 100], 0.1))->toBe(100)
        ->and(RollingDurationStats::typicalSeconds([60, 600, 600, 600, 7200], 0.1))->toBe(600) // five: one cut each end at least
        ->and(RollingDurationStats::typicalSeconds([100, 500, 900, 1300, 1700], 0.0))->toBe(900);
});
