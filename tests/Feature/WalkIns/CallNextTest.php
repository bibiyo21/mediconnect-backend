<?php

use App\Modules\Notifications\Jobs\SendPushNotification;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Increment 5.6-2: call-next and explicit call (plan section 4, decisions D4 to D8, D13, D14). The order is triage, then the time the
 * patient became ready (queue_sort_at), then the number; REGISTERED entries are never callable; the doctor must be IN.
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
});

it('calls the head of the queue: triage first, then the time they became ready, then the number', function () {
    $a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');
    $c = wi_triaged($this->staff, $this->svc, 'Charlie Guest');
    $queue = wi_queue_id();

    $r = wi_call_next($queue, $this->staff)->assertOk();
    expect($r->json('data.id'))->toBe($c)->and($r->json('data.status'))->toBe('CALLED')->and($r->json('data.recall_count'))->toBe(0)->and($r->json('data.called_at'))->toBe($this->clock->now()->toIso8601ZuluString());
    expect(array_key_exists('queue_sort_at', $r->json('data')))->toBeFalse();

    wi_post("{$c}/skip", $this->staff)->assertOk();
    expect(wi_call_next($queue, $this->staff)->assertOk()->json('data.id'))->toBe($a);
    wi_post("{$a}/skip", $this->staff)->assertOk();
    expect(wi_call_next($queue, $this->staff)->assertOk()->json('data.id'))->toBe($b);
});

it('sorts a returned entry by its return time and never calls a REGISTERED one', function () {
    $a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');
    [, $profile, $headers] = remote_patient();
    $remote = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    $queue = wi_queue_id();

    expect(wi_call_next($queue, $this->staff)->json('data.id'))->toBe($a);
    wi_post("{$a}/skip", $this->staff)->assertOk();
    wi_advance(3);
    wi_post("{$a}/return", $this->staff)->assertOk()->assertJsonPath('data.status', 'WAITING');

    expect(wi_call_next($queue, $this->staff)->json('data.id'))->toBe($b);
    wi_post("{$b}/skip", $this->staff)->assertOk();
    wi_advance(3);
    wi_post("{$b}/return", $this->staff)->assertOk();
    // a returned before b, so a is ahead; the REGISTERED remote entry is never in the order.
    expect(wi_call_next($queue, $this->staff)->json('data.id'))->toBe($a)->and(wi_status($remote))->toBe('REGISTERED');
    wi_post("{$a}/skip", $this->staff)->assertOk();
    wi_post("{$a}/no-show", $this->staff)->assertOk();
    wi_post("{$b}/no-show", $this->staff)->assertStatus(409);
    wi_post("{$b}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'QUEUE_EMPTY');
});

it('makes the consultation, the event, the audit row and the doctor assignment with the call; a guest has no patient', function () {
    $g = wi_guest($this->staff, $this->svc, 'Dela Cruz Guest');
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();

    $entry = DB::table('walkin_queue_entries')->where('id', $g)->first();
    $consultation = DB::table('consultations')->where('walkin_queue_entry_id', $g)->sole();
    $event = DB::table('walkin_queue_events')->where('event_type', 'CALLED')->sole();
    expect($entry->assigned_doctor_profile_id)->toBe($this->w['doctor']->id)->and($entry->recall_count)->toBe(0)->and($entry->called_at)->not->toBeNull()
        ->and($consultation->status)->toBe('SCHEDULED')->and($consultation->patient_profile_id)->toBeNull()->and($consultation->doctor_profile_id)->toBe($this->w['doctor']->id)
        ->and($event->from_status)->toBe('WAITING')->and($event->to_status)->toBe('CALLED')->and($event->metadata)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'walkins.called')->count())->toBe(1)
        ->and(DB::table('notifications')->where('type', 'walkins.called')->count())->toBe(0); // a guest has no notification
});

it('gives a registered patient a generic in-app notice and an own-account hint only', function () {
    [$user, $profile, $headers] = remote_patient();
    $remote = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    wi_post("{$remote}/arrive", $this->staff)->assertOk();

    wi_call_next(wi_queue_id(), $this->staff)->assertOk();

    $notice = DB::table('notifications')->where('type', 'walkins.called')->sole();
    $data = json_decode($notice->data, true);
    expect($notice->notifiable_id)->toBe($user->id)->and($data['title'])->toBe('Your turn at the clinic')->and($data['body'])->toBe('Please go to the clinic desk now.')
        ->and(array_keys($data))->toEqualCanonicalizing(['entry_id', 'title', 'body']);
    // The patient sees status and estimate only: no staff fields.
    $own = wi_json("/api/v1/walk-ins/{$remote}", $headers)->assertOk()->json('data');
    expect($own['status'])->toBe('CALLED')->and($own)->not->toHaveKeys(['recall_count', 'called_at', 'queue_sort_at', 'name']);
    // The staff detail shows them, still no sort key.
    $staff = wi_json("/api/v1/walk-ins/{$remote}", $this->staff)->assertOk()->json('data');
    expect($staff)->toHaveKeys(['recall_count', 'called_at'])->and($staff)->not->toHaveKey('queue_sort_at');
});

it('notifies a guardian who is still authorized and never one who was revoked', function () {
    [$guardian, , $headers] = remote_patient();
    $dependant = wi_dependent($headers);
    $id = register_walkin($headers, patient_body($this->svc, $dependant))->assertCreated()->json('data.id');
    wi_post("{$id}/arrive", $this->staff)->assertOk();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    expect(DB::table('notifications')->where('type', 'walkins.called')->where('notifiable_id', $guardian->id)->count())->toBe(1);

    wi_post("{$id}/skip", $this->staff)->assertOk();
    DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->update(['authorization_status' => 'REVOKED', 'revoked_at' => now()]);
    wi_post("{$id}/return", $this->staff)->assertOk();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    expect(DB::table('notifications')->where('type', 'walkins.called')->where('notifiable_id', $guardian->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('type', 'walkins.skipped')->where('notifiable_id', $guardian->id)->count())->toBe(1);
});

it('refuses when the doctor is on a break, unavailable, not checked in or checked out (409 DOCTOR_NOT_AVAILABLE with a reason code)', function () {
    $g = wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();

    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])->assertOk();
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'DOCTOR_NOT_AVAILABLE')->assertJsonPath('reason_code', 'on_break');
    presence_post('break/end', $this->w['doctorHeaders'])->assertOk();

    presence_post('unavailable', $this->w['doctorHeaders'])->assertOk();
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'unavailable');
    presence_post('available', $this->w['doctorHeaders'])->assertOk();

    DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->now(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'checked_out');
    // Another doctor who never checked in: staff may register before check-in (B2), but a call needs the doctor in.
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $otherStaff = staff_of($other);
    wi_guest($otherStaff, $other['service']);
    wi_call_next((string) DB::table('walkin_queues')->where('doctor_profile_id', $other['doctor']->id)->value('id'), $otherStaff)->assertStatus(409)->assertJsonPath('reason_code', 'not_checked_in');

    expect(wi_status($g))->toBe('WAITING')->and(DB::table('consultations')->count())->toBe(0);
});

it('refuses a call on a queue with no doctor with reason no_doctor, never not_checked_in', function () {
    $g = wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();
    DB::table('walkin_queues')->where('id', $queue)->update(['doctor_profile_id' => null]);

    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'DOCTOR_NOT_AVAILABLE')->assertJsonPath('reason_code', 'no_doctor');
    expect(wi_status($g))->toBe('WAITING')->and(DB::table('consultations')->count())->toBe(0);
});

it('calls on a paused queue (a pause stops registration only) and refuses a closed one or one of another day', function () {
    $g = wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();
    wi_post("queues/{$queue}/pause", $this->staff)->assertOk();
    wi_call_next($queue, $this->staff)->assertOk()->assertJsonPath('data.id', $g);
    wi_post("{$g}/skip", $this->staff)->assertOk();

    DB::table('walkin_queues')->where('id', $queue)->update(['status' => 'CLOSED', 'closed_at' => now()]);
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'closed');
    DB::table('walkin_queues')->where('id', $queue)->update(['status' => 'OPEN', 'closed_at' => null, 'local_date' => $this->clock->now()->setTimezone('Asia/Manila')->subDay()->toDateString()]);
    wi_post("{$g}/return", $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'not_today');
});

it('refuses a second call while one entry is CALLED, and an empty queue', function () {
    $a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    wi_guest($this->staff, $this->svc, 'Bravo Guest');
    $queue = wi_queue_id();

    wi_call_next($queue, $this->staff)->assertOk();
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'ENTRY_ALREADY_CALLED')->assertJsonPath('errors.entry_id.0', $a);
    wi_post("{$a}/skip", $this->staff)->assertOk();
    DB::table('walkin_queue_entries')->update(['status' => 'CANCELLED']);
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'QUEUE_EMPTY');
});

it('refuses a call while the same doctor has a patient called in another queue (409 DOCTOR_ALREADY_CALLING)', function () {
    wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();
    wi_second_queue_for_doctor($this->w, 'CALLED');

    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'DOCTOR_ALREADY_CALLING')->assertJsonPath('reason_code', 'other_queue');
    expect(DB::table('walkin_queue_entries')->where('status', 'CALLED')->count())->toBe(1);
});

it('refuses a call when the doctor is no longer assigned to the location, or the location or doctor is no longer active', function () {
    wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();

    DB::table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->update(['status' => 'ENDED', 'effective_until' => now(), 'end_reason' => 'Left the clinic']);
    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'DOCTOR_NOT_AVAILABLE')->assertJsonPath('reason_code', 'no_assignment');
    expect(DB::table('walkin_queue_entries')->where('status', 'CALLED')->count())->toBe(0)->and(DB::table('consultations')->count())->toBe(0);
});

it('refuses a call while a stale CALLED entry from yesterday holds the doctor, with no ids in the body; skipping it clears the way', function () {
    $g = wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();
    [$staleQueue, $stale] = wi_second_queue_for_doctor($this->w, 'CALLED');
    DB::table('walkin_queues')->where('id', $staleQueue)->update(['local_date' => $this->clock->now()->setTimezone('Asia/Manila')->subDay()->toDateString()]);

    $response = wi_call_next($queue, $this->staff);
    $response->assertStatus(409)->assertJsonPath('code', 'DOCTOR_ALREADY_CALLING')->assertJsonPath('reason_code', 'previous_day');
    expect($response->getContent())->not->toContain($stale)->not->toContain($staleQueue)->not->toContain($g);

    wi_post("{$stale}/skip", $this->staff)->assertOk()->assertJsonPath('data.status', 'SKIPPED');
    wi_call_next($queue, $this->staff)->assertOk()->assertJsonPath('data.id', $g);
});

it('calls an explicit entry only when it is the head of the order (NOT_NEXT_IN_ORDER otherwise)', function () {
    $a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');

    wi_post("{$b}/call", $this->staff)->assertStatus(409)->assertJsonPath('code', 'NOT_NEXT_IN_ORDER');
    expect(wi_status($b))->toBe('WAITING');
    wi_post("{$a}/call", $this->staff)->assertOk()->assertJsonPath('data.status', 'CALLED');
    // A repeat call of the called entry is an illegal move, not another call.
    wi_post("{$a}/call", $this->staff)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'CALLED');
    wi_post("{$b}/call", $this->staff)->assertStatus(409)->assertJsonPath('code', 'ENTRY_ALREADY_CALLED');
});

it('lets the queue\'s own doctor call from the doctor app', function () {
    wi_guest($this->staff, $this->svc);

    wi_call_next(wi_queue_id(), $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'CALLED');
});

it('needs an Idempotency-Key, and a replay makes no second call', function () {
    $a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    wi_guest($this->staff, $this->svc, 'Bravo Guest');
    $queue = wi_queue_id();
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/queues/{$queue}/call-next", [], $this->staff)->assertStatus(400);

    $key = (string) Str::uuid7();
    wi_call_next($queue, $this->staff, $key)->assertOk()->assertJsonPath('data.id', $a);
    wi_call_next($queue, $this->staff, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $a);
    expect(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(1);

    // A refused attempt is replayed for the key too, so a client sends a NEW key for each attempt.
    $again = (string) Str::uuid7();
    wi_call_next($queue, $this->staff, $again)->assertStatus(409);
    wi_post("{$a}/skip", $this->staff)->assertOk();
    wi_call_next($queue, $this->staff, $again)->assertStatus(409)->assertHeader('Idempotency-Replayed', 'true');
    wi_call_next($queue, $this->staff)->assertOk();
});

it('answers 404 for an unknown queue or entry and validates the override code', function () {
    wi_call_next((string) Str::uuid7(), $this->staff)->assertNotFound();
    wi_post(Str::uuid7().'/call', $this->staff)->assertNotFound();
    $a = wi_guest($this->staff, $this->svc);
    wi_post("{$a}/call", $this->staff, ['override_reason_code' => 'BORED'])->assertStatus(422);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/walk-ins/not-a-uuid/call', [], [...$this->staff, 'Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
});

it('does not log or store a guest name or phone anywhere in the call records', function () {
    wi_guest($this->staff, $this->svc, 'Zzyzx Quillfeather');
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();

    $blob = json_encode([DB::table('audit_logs')->get(), DB::table('walkin_queue_events')->get(), DB::table('notifications')->get(), DB::table('security_events')->get()]);
    expect($blob)->not->toContain('Zzyzx')->not->toContain('Quillfeather')->not->toContain('09171234567')->not->toContain('+639171234567');
});

it('refuses a call on a queue of another day (not_today), for call-next and for call', function () {
    $a = wi_guest($this->staff, $this->svc);
    $queue = wi_queue_id();
    DB::table('walkin_queues')->where('id', $queue)->update(['local_date' => $this->clock->now()->setTimezone('Asia/Manila')->subDay()->toDateString()]);

    wi_call_next($queue, $this->staff)->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'not_today');
    wi_post("{$a}/call", $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'not_today');
    expect(wi_status($a))->toBe('WAITING');
});

it('keeps operational notices in the app: no push delivery is recorded or queued', function () {
    [$user, $profile, $headers] = remote_patient();
    $id = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    wi_post("{$id}/arrive", $this->staff)->assertOk();
    Queue::fake();

    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    wi_post("{$id}/recall", $this->staff)->assertOk();
    wi_post("{$id}/skip", $this->staff)->assertOk();
    wi_post("{$id}/no-show", $this->staff)->assertOk();

    Queue::assertNotPushed(SendPushNotification::class);
    $ids = DB::table('notifications')->whereIn('type', ['walkins.called', 'walkins.recalled', 'walkins.skipped', 'walkins.no_show'])->pluck('id');
    expect($ids)->toHaveCount(4)->and(DB::table('notification_deliveries')->whereIn('notification_id', $ids)->pluck('channel')->unique()->all())->toBe(['IN_APP']);
});

it('answers with the view of the channel the caller acted through', function () {
    wi_guest($this->staff, $this->svc, 'Alpha Guest');
    $queue = wi_queue_id();

    $doctor = wi_call_next($queue, $this->w['doctorHeaders'])->assertOk();
    expect($doctor->json('data.name'))->toBe('Alpha Guest')->and($doctor->json('data'))->not->toHaveKey('guest_phone');
});
