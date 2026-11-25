<?php

use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Scheduling\Services\AvailabilityProjector;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.5a: the schedule's view of a walk-in day (capacity, cutoff, breaks, gaps, protected appointments and holds) and
 * how the registration wires it into the wait estimator (walk-in-management.md §3.5).
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world();
    $this->svc = $this->w['service'];
    $this->day = fn (array $options = []) => app(WalkInDay::class)->forDoctorAtLocation($this->w['doctor']->id, $this->w['location'], $this->clock->now()->setTimezone('Asia/Manila')->toDateString(), ...$options);
});

it('derives the capacity, closing and cutoff base from the schedule (decision 100)', function () {
    $facts = ($this->day)();

    expect($facts->hasWalkInHours)->toBeTrue()->and($facts->clinicClosedToday)->toBeFalse()->and($facts->capacity)->toBe(7)->and($facts->timezone)->toBe('Asia/Manila')
        ->and(wi_local($facts->clinicClosesAt))->toBe('17:00')->and(wi_local($facts->lastWindowEndsAt))->toBe('12:00')->and(wi_local($facts->closesAt()))->toBe('12:00')
        ->and($facts->offShiftGaps)->toBe([])->and($facts->scheduledBreaks)->toBe([]);
    expect(app(WalkInDay::class)->offersWalkIn($this->svc, '2026-12-07'))->toBeTrue();
});

it('computes split-shift capacity from walk-in windows and an explicit cap, with the gap and the break', function () {
    wi_schedule($this->w, [
        ['starts_at' => '09:00', 'ends_at' => '11:00', 'breaks' => [['starts_at' => '10:00', 'ends_at' => '10:15']]],
        ['starts_at' => '13:00', 'ends_at' => '15:00', 'walkin_capacity' => 3],
    ]);

    $facts = ($this->day)();

    // First shift: 09:00-10:00 (60) and 10:15-11:00 (45) minutes, 25 per place: floor(60/25) + floor(45/25) is not how it is summed; the
    // window minutes of the shift (105) divided by 25 give 4. Second shift: the explicit 3.
    expect($facts->capacity)->toBe(4 + 3)->and(wi_local($facts->lastShiftEndsAt))->toBe('15:00')->and(wi_local($facts->closesAt()))->toBe('15:00')
        ->and(array_map(fn ($w) => wi_local($w->startsAt).'-'.wi_local($w->endsAt), $facts->offShiftGaps))->toBe(['11:00-13:00'])
        ->and(array_map(fn ($w) => wi_local($w->startsAt).'-'.wi_local($w->endsAt), $facts->scheduledBreaks))->toBe(['10:00-10:15'])
        ->and(array_map(fn ($w) => wi_local($w->startsAt).'-'.wi_local($w->endsAt), $facts->shiftSpans))->toBe(['09:00-11:00', '13:00-15:00']);
});

it('gives no walk-in hours on a day with a leave, a closure, or no shift, and counts nothing for a blocked shift', function () {
    $monday = $this->clock->now()->setTimezone('Asia/Manila')->toDateString();
    expect(($this->day)()->hasWalkInHours)->toBeTrue();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/schedule-exceptions', ['exception_type' => 'SICK_LEAVE', 'starts_at' => $monday.'T00:00:00+08:00', 'ends_at' => $monday.'T23:59:00+08:00', 'reason' => 'Flu'], $this->w['doctorHeaders'])->assertCreated();
    $blocked = ($this->day)();
    expect($blocked->hasWalkInHours)->toBeFalse()->and($blocked->capacity)->toBe(0)->and($blocked->clinicClosedToday)->toBeFalse()->and($blocked->closesAt())->not->toBeNull(); // the clinic itself is open until 17:00

    $tuesday = app(WalkInDay::class)->forDoctorAtLocation($this->w['doctor']->id, $this->w['location'], CarbonImmutable::parse($monday)->addDay()->toDateString());
    expect($tuesday->hasWalkInHours)->toBeFalse();
});

it('reports a clinic closure as closed, and counts only shifts of the queue\'s own clinic service', function () {
    $monday = $this->clock->now()->setTimezone('Asia/Manila')->toDateString();

    // Shifts are for doctor services with no clinic service here: another clinic service id finds none, "any" finds them.
    expect(($this->day)([Str::uuid7()->toString()])->hasWalkInHours)->toBeFalse()->and(($this->day)([null, true])->capacity)->toBe(7);

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/clinics/{$this->w['clinic']}/holidays", ['date' => $monday, 'name' => 'Founding day', 'is_closed' => true], $this->w['manager'])->assertCreated();
    $closed = ($this->day)();
    expect($closed->clinicClosedToday)->toBeTrue()->and($closed->clinicClosesAt)->toBeNull()->and($closed->hasWalkInHours)->toBeFalse();
});

it('blocks the estimate for a live appointment until its late grace has passed (m2d), including an online one (m2e)', function () {
    doctor_in($this->w);
    $staff = staff_of($this->w);
    $now = $this->clock->now();

    // A 10 minute in-person appointment at 10:10: the 20 minute walk-in visit cannot fit before it.
    wi_appointment($this->w, $now->addMinutes(10), $now->addMinutes(20));
    $r = register_walkin($staff, guest_body($this->svc))->assertCreated();
    // Protected until the grace (default 15 minutes from the start) ends at 10:25, so the earliest start is 25 minutes away.
    expect($r->json('data.estimate.basis'))->toContain('protected_appointment')->and($r->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(25);

    // Without the grace only the visit itself is protected: 10:10 to 10:20, so the earliest start is 20 minutes away, not 25. The first guest is out of the queue.
    DB::table('walkin_queue_entries')->update(['status' => 'CANCELLED']);
    config(['walkins.estimation.appointment_grace_minutes' => 0]);
    $nograce = register_walkin($staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]))->assertCreated();
    expect($nograce->json('data.estimate.basis'))->toContain('protected_appointment')->and($nograce->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(20)->toBeLessThan(25);
    DB::table('appointments')->delete();

    // An online appointment blocks in-person walk-ins for its time too.
    app('auth')->forgetGuards();
    $online = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'fee_amount' => 50000], $this->w['doctorHeaders'])->assertCreated()->json('data.id');
    $onlineMode = $this->putJson("/api/v1/doctors/me/services/{$online}/mode", ['service_mode' => 'APPOINTMENT'], $this->w['doctorHeaders'])->assertOk()->json('data.service_mode_id');
    wi_appointment($this->w, $now->addMinutes(5), $now->addMinutes(35), $online, $onlineMode, null);
    $third = register_walkin($staff, guest_body($this->svc, 'Third Guest', ['guest' => ['name' => 'Third Guest']]))->assertCreated();
    expect($third->json('data.estimate.basis'))->toContain('protected_appointment')->and($third->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(35);
});

it('blocks the estimate for an ACTIVE unexpired hold only (m2c)', function () {
    doctor_in($this->w);
    $staff = staff_of($this->w);
    $now = $this->clock->now();
    $monday = $now->setTimezone('Asia/Manila')->toDateString();
    app(AvailabilityProjector::class)->refresh($this->w['doctor']->id, $monday, $monday);
    $slot = (string) DB::table('doctor_availability_slots')->where('doctor_profile_id', $this->w['doctor']->id)->orderBy('starts_at')->value('id');

    wi_hold($this->w, $slot, $now->addMinutes(10), $now->addMinutes(30), $now->subMinute()); // expired a minute ago: no longer protected, whatever its status says
    expect(register_walkin($staff, guest_body($this->svc))->assertCreated()->json('data.estimate.basis'))->not->toContain('protected_hold');

    DB::table('appointment_slot_reservations')->update(['status' => 'RELEASED']);
    wi_hold($this->w, $slot, $now->addMinutes(10), $now->addMinutes(30), $now->addMinutes(8));
    $held = register_walkin($staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]))->assertCreated();
    expect($held->json('data.estimate.basis'))->toContain('protected_hold');
});

it('treats a gap between shifts and a scheduled break as blocked time', function () {
    wi_schedule($this->w, [
        ['starts_at' => '09:00', 'ends_at' => '10:30', 'breaks' => [['starts_at' => '10:05', 'ends_at' => '10:25']]],
        ['starts_at' => '13:00', 'ends_at' => '15:00'],
    ]);
    doctor_in($this->w);

    $r = register_walkin(staff_of($this->w), guest_body($this->svc))->assertCreated();

    // A 20 minute visit does not fit before the 10:05 break; after it the shift ends at 10:30, so it runs into the gap until 13:00.
    expect($r->json('data.estimate.basis'))->toContain('scheduled_break')->and($r->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(25);
});

it('counts a consultation in progress, from any source, as the first thing the doctor is doing (m2a, decision 214)', function () {
    doctor_in($this->w);
    $staff = staff_of($this->w);
    $first = register_walkin($staff, guest_body($this->svc, 'First Guest'))->assertCreated()->json('data.id');
    DB::table('consultations')->insert([
        'id' => (string) Str::uuid7(), 'walkin_queue_entry_id' => $first, 'doctor_profile_id' => $this->w['doctor']->id, 'clinic_location_id' => $this->w['location'], 'consultation_type' => 'IN_PERSON',
        'status' => 'IN_PROGRESS', 'started_at' => $this->clock->now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('walkin_queue_entries')->where('id', $first)->update(['status' => 'IN_CONSULTATION', 'consultation_started_at' => $this->clock->now()->subMinutes(5), 'assigned_doctor_profile_id' => $this->w['doctor']->id]);

    $r = register_walkin($staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]))->assertCreated();

    // 20 minutes expected, 5 spent: about 15 left (spread 25 percent either way).
    expect($r->json('data.estimate.basis'))->toContain('current_consultation')->and($r->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(10);
});
