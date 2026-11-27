<?php

use App\Modules\Scheduling\Services\AvailabilityProjector;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Increment 5.6-2: the appointment window and the override (plan D7, D8, D18). A call is refused when the visit it starts would run into a
 * confirmed appointment (protected until its late grace has passed) or an active hold. Only a triaged patient, called explicitly by someone
 * holding walkins.manage_queue with a reason code, can pass that refusal; call-next never can.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    $this->now = $this->clock->now();
});

it('refuses a call that would run into a confirmed appointment, naming the reason and when it ends (409 APPOINTMENT_WINDOW_PROTECTED)', function () {
    $a = wi_guest($this->staff, $this->svc);
    wi_appointment($this->w, $this->now->addMinutes(10), $this->now->addMinutes(30));

    $r = wi_call_next(wi_queue_id(), $this->staff)->assertStatus(409)->assertJsonPath('code', 'APPOINTMENT_WINDOW_PROTECTED')->assertJsonPath('reason_code', 'appointment');
    expect($r->json('errors.blocked_until.0'))->toBe($this->now->addMinutes(30)->toIso8601ZuluString())->and(wi_status($a))->toBe('WAITING')->and(DB::table('consultations')->count())->toBe(0);
    wi_post("{$a}/call", $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'appointment');

    // A visit that is over before the appointment starts is fine: 20 minutes nominal, appointment in 25.
    DB::table('appointments')->update(['starts_at' => $this->now->addMinutes(25), 'ends_at' => $this->now->addMinutes(45)]);
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
});

it('refuses a call against an ACTIVE hold, and not against an expired one', function () {
    $a = wi_guest($this->staff, $this->svc);
    $monday = $this->now->setTimezone('Asia/Manila')->toDateString();
    app(AvailabilityProjector::class)->refresh($this->w['doctor']->id, $monday, $monday);
    $slot = (string) DB::table('doctor_availability_slots')->where('doctor_profile_id', $this->w['doctor']->id)->orderBy('starts_at')->value('id');

    wi_hold($this->w, $slot, $this->now->addMinutes(5), $this->now->addMinutes(25), $this->now->subMinute());
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    wi_post("{$a}/skip", $this->staff)->assertOk();
    wi_post("{$a}/return", $this->staff)->assertOk();

    DB::table('appointment_slot_reservations')->delete();
    wi_hold($this->w, $slot, $this->now->addMinutes(5), $this->now->addMinutes(25), $this->now->addMinutes(8));
    wi_call_next(wi_queue_id(), $this->staff)->assertStatus(409)->assertJsonPath('code', 'APPOINTMENT_WINDOW_PROTECTED')->assertJsonPath('reason_code', 'hold');
});

it('keeps an appointment that has started and ended protected until its late grace has passed', function () {
    wi_guest($this->staff, $this->svc);
    wi_appointment($this->w, $this->now->subMinutes(10), $this->now->subMinutes(5)); // 09:50-09:55; the grace (15 minutes) runs to 10:05

    wi_call_next(wi_queue_id(), $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'appointment');
    wi_advance(6);
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
});

it('starts the window when the consultation in progress is expected to end, not now (D8)', function () {
    wi_guest($this->staff, $this->svc);
    // A consultation that began five minutes ago on another queue of the doctor: expected to last 20 minutes, so a call now sees the patient at about 10:15.
    [, $busy] = wi_second_queue_for_doctor($this->w, 'WAITING', 'B');
    DB::table('walkin_queue_entries')->where('id', $busy)->update(['status' => 'IN_CONSULTATION', 'assigned_doctor_profile_id' => $this->w['doctor']->id, 'consultation_started_at' => $this->now->subMinutes(5)]);
    DB::table('consultations')->insert(['id' => (string) Str::uuid7(), 'walkin_queue_entry_id' => $busy, 'doctor_profile_id' => $this->w['doctor']->id, 'clinic_location_id' => $this->w['location'], 'consultation_type' => 'IN_PERSON', 'status' => 'IN_PROGRESS', 'started_at' => $this->now->subMinutes(5)]);
    wi_appointment($this->w, $this->now->addMinutes(25), $this->now->addMinutes(45));

    wi_call_next(wi_queue_id(), $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'appointment');
    DB::table('appointments')->update(['starts_at' => $this->now->addMinutes(45), 'ends_at' => $this->now->addMinutes(65)]);
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
});

it('does not let a scheduled break or the end of the shift refuse a call', function () {
    wi_guest($this->staff, $this->svc);
    wi_schedule($this->w, [['starts_at' => '09:00', 'ends_at' => '10:30', 'breaks' => [['starts_at' => '10:05', 'ends_at' => '10:25']]]]);

    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
});

it('lets staff override for a triaged patient with a reason code; the override is recorded in the event and the audit row (D7, D18)', function () {
    $u = wi_triaged($this->staff, $this->svc, 'Urgent Guest');
    wi_appointment($this->w, $this->now->addMinutes(10), $this->now->addMinutes(30));
    $queue = wi_queue_id();

    // Call-next never overrides: a body that asks is refused.
    wi_post("queues/{$queue}/call-next", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertStatus(422)->assertJsonValidationErrorFor('override_reason_code', 'errors');
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'APPOINTMENT_WINDOW_PROTECTED');
    wi_post("{$u}/call", $this->staff)->assertStatus(409)->assertJsonPath('code', 'APPOINTMENT_WINDOW_PROTECTED');

    wi_post("{$u}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertOk()->assertJsonPath('data.status', 'CALLED');

    $event = DB::table('walkin_queue_events')->where('event_type', 'CALLED')->sole();
    $audit = DB::table('audit_logs')->where('action', 'walkins.called')->sole();
    expect(json_decode($event->metadata, true))->toBe(['override' => true, 'reason_code' => 'STAFF_CONCERN'])
        ->and(json_encode(json_decode(json_encode($audit), true)))->toContain('override')->toContain('STAFF_CONCERN');
});

it('refuses an override for a patient with no triage (422) and does not call', function () {
    $a = wi_guest($this->staff, $this->svc);
    wi_appointment($this->w, $this->now->addMinutes(10), $this->now->addMinutes(30));

    wi_post("{$a}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertStatus(422)->assertJsonValidationErrorFor('override_reason_code', 'errors');
    expect(wi_status($a))->toBe('WAITING')->and(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(0);
});

it('lets the override pass the window only: the order and the presence rules still hold', function () {
    $a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    $u = wi_triaged($this->staff, $this->svc, 'Priority Guest', 'PRIORITY');
    wi_appointment($this->w, $this->now->addMinutes(10), $this->now->addMinutes(30));

    // Out of order is not allowed even with an override.
    wi_post("{$a}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertStatus(409)->assertJsonPath('code', 'NOT_NEXT_IN_ORDER');
    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])->assertOk();
    wi_post("{$u}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertStatus(409)->assertJsonPath('code', 'DOCTOR_NOT_AVAILABLE');
    presence_post('break/end', $this->w['doctorHeaders'])->assertOk();
    wi_post("{$u}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertOk();
});

it('records no override when there was nothing to pass', function () {
    $u = wi_triaged($this->staff, $this->svc, 'Urgent Guest');

    wi_post("{$u}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertOk();

    expect(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->sole()->metadata)->toBeNull();
});

it('merges adjacent protected windows, so blocked_until is when the whole run ends', function () {
    wi_guest($this->staff, $this->svc);
    wi_appointment($this->w, $this->now->addMinutes(10), $this->now->addMinutes(30));
    wi_appointment($this->w, $this->now->addMinutes(30), $this->now->addMinutes(50));
    wi_appointment($this->w, $this->now->addMinutes(120), $this->now->addMinutes(140)); // a gap: not part of the run

    $r = wi_call_next(wi_queue_id(), $this->staff)->assertStatus(409);
    expect($r->json('errors.blocked_until.0'))->toBe($this->now->addMinutes(50)->toIso8601ZuluString());
});

it('refuses an override from a member whose role lacks walkins.manage_queue (the call itself needs only call_patient)', function () {
    $u = wi_triaged($this->staff, $this->svc, 'Urgent Guest');
    wi_appointment($this->w, $this->now->addMinutes(10), $this->now->addMinutes(30));
    Role::findByName('CLINIC_STAFF', 'web')->revokePermissionTo('walkins.manage_queue');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    wi_post("{$u}/call", $this->staff, ['override_reason_code' => 'STAFF_CONCERN'])->assertStatus(403);
    expect(wi_status($u))->toBe('WAITING');
});
