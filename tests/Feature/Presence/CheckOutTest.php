<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\Presence\Services\PresenceActor;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.8-1: doctor check-out (brief D-B to D-E, D-J, D-K). The session is the first lock; a consultation in progress or interrupted, or a
 * called walk-in, refuses before anything changes; a walk-in disposition is required; appointments are flagged and never cancelled.
 */

/** A walk-in patient registered and arrived (WAITING): the entry id. */
function co_arrived(array $w, array $staff, array $patientHeaders, string $profile): string
{
    fresh_session();
    $entry = register_walkin($patientHeaders, patient_body($w['service'], $profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $staff)->assertOk();

    return $entry;
}

/** The doctor's body for a check-out. */
function co_body(string $disposition = 'HOLD_FOR_REASSIGNMENT', array $extra = []): array
{
    return ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => $disposition, ...$extra];
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    // The check-out reads the same pinned clock as the other presence writes (pin_clock does not list it).
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    $this->doctorId = (string) $this->w['doctor']->id;
    $this->doctorUserId = (string) $this->w['doctor']->user_id;
});

it('checks the doctor out with nothing waiting: OUT, the session closed, the event and the audit row written', function () {
    app('auth')->forgetGuards();
    $r = $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk()
        ->assertJsonPath('data.status', 'OUT')->assertJsonPath('data.session_id', fn ($id) => is_string($id))->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.result.queues_paused', 0)->assertJsonPath('data.result.entries_held', 0)->assertJsonPath('data.result.entries_cancelled', 0)
        ->assertJsonPath('data.result.appointments_flagged', 0)->assertJsonPath('data.result.tasks_created', 0);

    expect($r->json('data.clinic_location_id'))->toBe($this->w['location']);
    $session = DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->sole();
    expect($session->current_status)->toBe('OUT')->and($session->checked_out_at)->not->toBeNull()
        ->and($session->checkout_reason)->toBe('END_OF_SHIFT')->and($session->checked_out_method)->toBe('DOCTOR_APP')
        ->and($session->checked_out_by)->toBe($this->doctorUserId)->and($session->current_break_expected_end_at)->toBeNull();

    $event = events_of($this->doctorId)->last();
    expect($event->previous_status.'>'.$event->new_status)->toBe('IN>OUT')->and($event->change_method)->toBe('DOCTOR_APP')
        ->and($event->reason)->toBe('END_OF_SHIFT')->and($event->changed_by)->toBe($this->doctorUserId);
    expect(DB::table('audit_logs')->where('action', 'presence.checked_out')->count())->toBe(1);
});

it('checks out, then the doctor can check in again as a new session (the presence state is not reopened)', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk();

    checkin($this->w['doctorHeaders'], $this->w['location'])->assertCreated()->assertJsonPath('data.status', 'IN');
    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->count())->toBe(2);
});

it('refuses a repeat check-out with 409 not_checked_in, and changes nothing the second time', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('reason_code', 'not_checked_in');
    expect(events_of($this->doctorId))->toHaveCount(2);
});

it('refuses while a consultation is in progress (409 ACTIVE_CONSULTATION, in_progress) and leaves the session open', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk()->assertJsonPath('data.id', $entry);
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL'), $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'in_progress');

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN')
        ->and(wi_status($entry))->toBe('IN_CONSULTATION')
        ->and(DB::table('doctor_presence_events')->where('new_status', 'OUT')->count())->toBe(0);
});

it('refuses while a consultation is interrupted (409 ACTIVE_CONSULTATION, interrupted): an interrupted consultation must be ended first', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    act_post("/api/v1/consultations/{$consultation}/interrupt", $this->w['doctorHeaders'])->assertOk();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'interrupted');
    expect(Consultation::query()->whereKey($consultation)->value('status')->value)->toBe('INTERRUPTED');

    // Ending it clears the block: end never refuses on presence (5.7 D-F).
    act_post("/api/v1/consultations/{$consultation}/end", $this->w['doctorHeaders'])->assertOk();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'OUT');
});

it('a refused check-out (in progress) changes nothing else: the waiting guest, the appointment and the queue stay as they were (m3)', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    $guest = register_walkin($this->staff, guest_body($this->svc))->assertCreated()->json('data.id');
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk()->assertJsonPath('data.id', $entry);
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL', ['guests_informed_in_person' => true]), $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('reason_code', 'in_progress');

    expect(wi_status($guest))->toBe('WAITING')->and(wi_status($entry))->toBe('IN_CONSULTATION')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('OPEN')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->count())->toBe(0)
        ->and(DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->value('needs_rescheduling'))->toBeFalse()
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0)
        ->and(DB::table('notifications')->whereIn('type', ['walkins.cancelled', 'walkins.doctor_checked_out'])->count())->toBe(0);
});

it('NOTIFY_AND_CANCEL also cancels a SKIPPED entry and its scheduled consultation (cancelForWalkIn), and HOLD keeps the skipped place (m3)', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    expect(Consultation::query()->whereKey($consultation)->value('status')->value)->toBe('SCHEDULED');
    fresh_session();
    wi_post("{$entry}/skip", $this->staff)->assertOk();
    expect(wi_status($entry))->toBe('SKIPPED');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL'), $this->w['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.result.entries_cancelled', 1);

    expect(wi_status($entry))->toBe('CANCELLED')
        ->and(Consultation::query()->whereKey($consultation)->value('status')->value)->toBe('CANCELLED');
    $cancel = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'CANCELLED')->sole();
    expect($cancel->from_status)->toBe('SKIPPED');
});

it('refuses while a patient CALLED on an earlier day is still open (409 called), and that stale queue is locked and untouched (M2)', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    // The runbook case: the call was made yesterday (Manila), and the doctor has checked in again today.
    $yesterday = $this->clock->now()->setTimezone('Asia/Manila')->subDay()->toDateString();
    DB::table('walkin_queues')->where('id', wi_queue_id())->update(['local_date' => $yesterday]);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL'), $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'called');

    expect(wi_status($entry))->toBe('CALLED')->and(DB::table('walkin_queues')->value('status'))->toBe('OPEN')
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_PAUSED')->count())->toBe(0);
});

it('refuses while a patient is called to the doctor (409 ACTIVE_CONSULTATION, called), and nothing is cancelled', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL'), $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'called');

    expect(wi_status($entry))->toBe('CALLED')->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');
});

it('NOTIFY_AND_CANCEL cancels the waiting patient with the DOCTOR_CHECKED_OUT reason and the notice, and pauses the queue', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL'), $this->w['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.result.entries_cancelled', 1)->assertJsonPath('data.result.queues_paused', 1)->assertJsonPath('data.result.entries_held', 0);

    expect(wi_status($entry))->toBe('CANCELLED');
    $cancel = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'CANCELLED')->sole();
    expect($cancel->reason)->toBe('DOCTOR_CHECKED_OUT')->and($cancel->from_status)->toBe('WAITING')->and($cancel->to_status)->toBe('CANCELLED')
        ->and(json_decode((string) $cancel->metadata, true))->toBe(['by' => 'DOCTOR']);
    expect(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('PAUSED');
    expect(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_PAUSED')->count())->toBe(1);
    expect(DB::table('notifications')->where('type', 'walkins.cancelled')->count())->toBe(1);
    // The notice names the entry by id only (the staff-cancel notice does the same), and the pause audit carries the clinic (5.8 m5).
    expect(json_decode((string) DB::table('notifications')->where('type', 'walkins.cancelled')->value('data'), true))->toMatchArray(['entry_id' => $entry])
        ->and(DB::table('audit_logs')->where('action', 'walkins.queue_paused')->value('clinic_id'))->not->toBeNull();
});

it('HOLD_FOR_REASSIGNMENT keeps the place, pauses the queue and sends the hold notice, with nothing cancelled', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.result.entries_held', 1)->assertJsonPath('data.result.entries_cancelled', 0)->assertJsonPath('data.result.queues_paused', 1);

    expect(wi_status($entry))->toBe('WAITING')->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('PAUSED');
    expect(DB::table('notifications')->where('type', 'walkins.doctor_checked_out')->count())->toBe(1)
        ->and(DB::table('notifications')->where('type', 'walkins.cancelled')->count())->toBe(0);
    expect(json_decode((string) DB::table('notifications')->where('type', 'walkins.doctor_checked_out')->value('data'), true))->toMatchArray(['entry_id' => $entry]);
});

it('a guest under NOTIFY_AND_CANCEL needs guests_informed_in_person (422) and nothing changes; with it, the guest is cancelled and recorded as told in person', function () {
    $guest = register_walkin($this->staff, guest_body($this->svc))->assertCreated()->json('data.id');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL'), $this->w['doctorHeaders'])
        ->assertStatus(422)->assertJsonValidationErrors(['guests_informed_in_person']);
    expect(wi_status($guest))->toBe('WAITING')->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body('NOTIFY_AND_CANCEL', ['guests_informed_in_person' => true]), $this->w['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.result.entries_cancelled', 1);
    expect(wi_status($guest))->toBe('CANCELLED');
    $cancel = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $guest)->where('event_type', 'CANCELLED')->sole();
    expect(json_decode((string) $cancel->metadata, true))->toMatchArray(['by' => 'DOCTOR', 'patient_informed_in_person' => true]);
});

it('flags the day\'s appointments for rescheduling, never cancels them, and opens one task each, once', function () {
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));

    app('auth')->forgetGuards();
    $first = $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk()
        ->assertJsonPath('data.result.appointments_flagged', 1)->assertJsonPath('data.result.tasks_created', 1);

    $appointment = DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->sole();
    expect($appointment->status)->toBe('CONFIRMED')->and((bool) $appointment->needs_rescheduling)->toBeTrue();
    $task = DB::table('rescheduling_tasks')->where('appointment_id', $appointment->id)->sole();
    expect($task->reason)->toBe('DOCTOR_CHECKOUT')->and($task->status)->toBe('OPEN');

    // Back in, and out again the same day: the appointment is flagged again, but no second task is opened.
    checkin($this->w['doctorHeaders'], $this->w['location'])->assertCreated();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk()
        ->assertJsonPath('data.result.appointments_flagged', 1)->assertJsonPath('data.result.tasks_created', 0);
    expect(DB::table('rescheduling_tasks')->where('appointment_id', $appointment->id)->count())->toBe(1)
        ->and(DB::table('appointments')->where('id', $appointment->id)->value('status'))->toBe('CONFIRMED');
    expect($first->json('data.result.tasks_created'))->toBe(1);
});

it('lets the location\'s clinic manager check a doctor out on behalf: STAFF_CONSOLE, with the acting user recorded', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body() + ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']], $this->w['manager'])
        ->assertOk()->assertJsonPath('data.status', 'OUT');

    expect(DB::table('notifications')->where('notifiable_id', $this->doctorUserId)->where('type', 'presence.changed_by_staff')->count())->toBe(1);

    $event = events_of($this->doctorId)->last();
    $session = DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->sole();
    expect($event->change_method)->toBe('STAFF_CONSOLE')->and($event->changed_by)->not->toBeNull()->and($event->changed_by)->not->toBe($this->doctorUserId)
        ->and($session->checked_out_method)->toBe('STAFF_CONSOLE')->and($session->checked_out_by)->toBe($event->changed_by);
});

it('flags only the live appointments of today at this location: not ended, not tomorrow, not cancelled (window boundaries, m3)', function () {
    $now = $this->clock->now();
    wi_appointment($this->w, $now->addHour(), $now->addHour()->addMinutes(30));
    wi_appointment($this->w, $now->subHours(3), $now->subHours(2));
    wi_appointment($this->w, $now->addDay()->addHour(), $now->addDay()->addHours(2)->addMinutes(30));
    wi_appointment($this->w, $now->addHours(2), $now->addHours(2)->addMinutes(30));
    DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->where('starts_at', '>', $now->addMinutes(90)->toIso8601String())
        ->where('starts_at', '<', $now->addDay()->toIso8601String())->update(['status' => 'CANCELLED']);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->w['doctorHeaders'])->assertOk()
        ->assertJsonPath('data.result.appointments_flagged', 1)->assertJsonPath('data.result.tasks_created', 1);
    expect(DB::table('appointments')->where('needs_rescheduling', true)->count())->toBe(1)
        ->and(DB::table('appointments')->where('status', 'CANCELLED')->count())->toBe(1);
});

it('lets an ADMIN check a doctor out at a location: method ADMIN recorded on the session and the event', function () {
    [, $headers] = admin_session(Role::Admin);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body() + ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']], $headers)
        ->assertOk()->assertJsonPath('data.status', 'OUT');

    expect(events_of($this->doctorId)->last()->change_method)->toBe('ADMIN')
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('checked_out_method'))->toBe('ADMIN');
});

it('refuses staff of another clinic, a patient, and a doctor checking out another doctor (403)', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body() + ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']], $other['manager'])
        ->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body(), $this->patient)->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body() + ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']], $other['doctorHeaders'])
        ->assertForbidden();

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');
});

it('validates the body: reason and walkin_disposition are required and must be fixed codes (422)', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', [], $this->w['doctorHeaders'])
        ->assertStatus(422)->assertJsonValidationErrors(['reason', 'walkin_disposition']);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', ['reason' => 'LUNCH', 'walkin_disposition' => 'CANCEL_ALL'], $this->w['doctorHeaders'])
        ->assertStatus(422)->assertJsonValidationErrors(['reason', 'walkin_disposition']);
    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');
});

it('the SYSTEM actor may not cancel walk-in entries: a LogicException before anything changes (m4)', function () {
    $entry = co_arrived($this->w, $this->staff, $this->patient, $this->profile);

    expect(fn () => app(PresenceCheckOut::class)->checkOut(
        PresenceActor::system(), $this->doctorId, $this->w['location'], CheckOutReason::from('END_OF_SHIFT'), CheckOutDisposition::from('NOTIFY_AND_CANCEL'), false,
    ))->toThrow(LogicException::class);

    expect(wi_status($entry))->toBe('WAITING')->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');
});

it('refuses an anonymous check-out (401)', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', co_body())->assertUnauthorized();
});
