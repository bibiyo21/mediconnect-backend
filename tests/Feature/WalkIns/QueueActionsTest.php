<?php

use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.5a: moves on an entry and its queue: arrive, cancel, pause and open (decisions 51 and 52). Calling, skipping,
 * no-show and closing come later.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->remote = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    $this->guest = register_walkin($this->staff, guest_body($this->svc))->assertCreated()->json('data.id');
});

it('marks a registered patient as arrived: WAITING with arrived_at, an ARRIVED event and an audit row', function () {
    $r = wi_post("{$this->remote}/arrive", $this->staff)->assertOk()->assertJsonPath('data.status', 'WAITING');

    expect($r->json('data.arrived_at'))->toBe($this->clock->now()->toIso8601ZuluString());
    $entry = DB::table('walkin_queue_entries')->where('id', $this->remote)->sole();
    $event = DB::table('walkin_queue_events')->where('event_type', 'ARRIVED')->sole();
    expect($entry->status)->toBe('WAITING')->and($event->from_status)->toBe('REGISTERED')->and($event->to_status)->toBe('WAITING')->and($event->walkin_queue_entry_id)->toBe($this->remote)
        ->and(DB::table('audit_logs')->where('action', 'walkins.arrived')->count())->toBe(1);
});

it('refuses to mark arrival twice, or a guest who is already waiting (409 INVALID_STATE_TRANSITION)', function () {
    wi_post("{$this->remote}/arrive", $this->staff)->assertOk();

    wi_post("{$this->remote}/arrive", $this->staff)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'WAITING');
    wi_post("{$this->guest}/arrive", $this->staff)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    expect(DB::table('walkin_queue_events')->where('event_type', 'ARRIVED')->count())->toBe(1);
});

it('lets only the location\'s staff and the queue\'s doctor mark arrival', function () {
    $second = wi_second_location($this->w);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    [, $otherDoctor] = verified_doctor('7654321', 'ACTIVE');

    // No standing at the queue: 404 (the entry's existence is not revealed). A global reader may read it, so changing it is a 403.
    foreach ([
        'the patient' => [$this->headers, 404], 'unrelated staff' => [staff_of($other), 404], 'unrelated manager' => [$other['manager'], 404],
        'staff of another location' => [staff_of($this->w, Role::ClinicStaff, $second), 404], 'another doctor' => [$otherDoctor, 404],
        'admin' => [session_for(account(Role::Admin)), 403], 'support' => [session_for(account(Role::SupportAgent)), 403], 'finance' => [session_for(account(Role::FinanceOfficer)), 404],
    ] as $who => [$headers, $status]) {
        wi_post("{$this->remote}/arrive", $headers)->assertStatus($status);
    }
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('status'))->toBe('REGISTERED');

    // The doctor of this queue holds walkins.manage_queue (scope SD).
    wi_post("{$this->remote}/arrive", $this->w['doctorHeaders'])->assertOk();
});

it('needs an Idempotency-Key and replays the same answer', function () {
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/{$this->remote}/arrive", [], $this->staff)->assertStatus(400);

    $key = (string) Str::uuid7();
    wi_post("{$this->remote}/arrive", $this->staff, [], $key)->assertOk();
    wi_post("{$this->remote}/arrive", $this->staff, [], $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect(DB::table('walkin_queue_events')->where('event_type', 'ARRIVED')->count())->toBe(1);
});

it('lets the patient cancel their own entry with a reason code, and frees the place', function () {
    $r = wi_post("{$this->remote}/cancel", $this->headers, ['reason_code' => 'PATIENT_REQUEST'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');

    expect($r->json('data'))->not->toHaveKey('name');
    $event = DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->sole();
    expect($event->reason)->toBe('PATIENT_REQUEST')->and($event->from_status)->toBe('REGISTERED')->and(json_decode($event->metadata, true))->toEqual(['by' => 'PATIENT'])
        ->and(DB::table('notifications')->where('type', 'walkins.cancelled')->count())->toBe(0);
    // The same patient can join again (the live-entry index no longer holds them).
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();
});

it('validates the cancel reason and refuses entries that cannot be cancelled', function () {
    wi_post("{$this->remote}/cancel", $this->headers, [])->assertStatus(422);
    wi_post("{$this->remote}/cancel", $this->headers, ['reason_code' => 'BORED'])->assertStatus(422);
    wi_post("{$this->remote}/cancel", $this->headers, ['reason_code' => 'PATIENT_REQUEST', 'extra' => 'x'])->assertOk();
    wi_post("{$this->remote}/cancel", $this->headers, ['reason_code' => 'PATIENT_REQUEST'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'CANCELLED');

    DB::table('walkin_queue_entries')->where('id', $this->guest)->update(['status' => 'COMPLETED', 'completed_at' => now()]);
    wi_post("{$this->guest}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertStatus(409);
});

it('lets staff cancel a patient\'s entry; the registrant gets a generic notice', function () {
    wi_post("{$this->remote}/cancel", $this->staff, ['reason_code' => 'DUPLICATE_ENTRY'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');

    $notice = DB::table('notifications')->where('type', 'walkins.cancelled')->sole();
    $data = json_decode($notice->data, true);
    expect($notice->notifiable_id)->toBe($this->user->id)->and($data['title'])->toBe('Your walk-in queue entry was cancelled')->and($data['body'])->toBe('Open the app for details.')
        ->and(array_keys($data))->toEqualCanonicalizing(['entry_id', 'title', 'body']);
    $event = DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->sole();
    expect($event->reason)->toBe('DUPLICATE_ENTRY')->and(json_decode($event->metadata, true))->toEqual(['by' => 'STAFF']);
});

it('makes staff confirm they told a guest in person before cancelling, because a guest has no notification', function () {
    wi_post("{$this->guest}/cancel", $this->staff, ['reason_code' => 'PATIENT_LEFT'])->assertStatus(422)->assertJsonValidationErrorFor('patient_informed_in_person', 'errors');
    wi_post("{$this->guest}/cancel", $this->staff, ['reason_code' => 'PATIENT_LEFT', 'patient_informed_in_person' => false])->assertStatus(422);
    expect(DB::table('walkin_queue_entries')->where('id', $this->guest)->value('status'))->toBe('WAITING');

    wi_post("{$this->guest}/cancel", $this->staff, ['reason_code' => 'PATIENT_LEFT', 'patient_informed_in_person' => true])->assertOk();
    $event = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $this->guest)->where('event_type', 'CANCELLED')->sole();
    expect(json_decode($event->metadata, true))->toEqual(['by' => 'STAFF', 'patient_informed_in_person' => true])->and(DB::table('notifications')->where('type', 'walkins.cancelled')->count())->toBe(0);
});

it('refuses a stranger, another patient and a global role to cancel', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    [, , $stranger] = remote_patient();

    foreach (['another patient' => [$stranger, 404], 'unrelated staff' => [staff_of($other), 404], 'admin' => [session_for(account(Role::Admin)), 403], 'finance' => [session_for(account(Role::FinanceOfficer)), 404]] as $who => [$headers, $status]) {
        wi_post("{$this->remote}/cancel", $headers, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertStatus($status);
    }
    // The patient may not cancel somebody else's entry (a guest's), either.
    wi_post("{$this->guest}/cancel", $this->headers, ['reason_code' => 'OTHER'])->assertNotFound();
    expect(DB::table('walkin_queue_entries')->whereIn('id', [$this->remote, $this->guest])->pluck('status')->unique()->sort()->values()->all())->toBe(['REGISTERED', 'WAITING']);
});

it('lets a guardian cancel an entry of their dependant', function () {
    $dependant = wi_dependent($this->headers);
    $id = register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated()->json('data.id');

    wi_post("{$id}/cancel", $this->headers, ['reason_code' => 'PATIENT_REQUEST'])->assertOk();
});

it('pauses a queue, refuses new registrations while paused, and opens it again (decisions 52 and 180)', function () {
    $queue = wi_queue_id();

    $r = wi_post("queues/{$queue}/pause", $this->staff)->assertOk()->assertJsonPath('data.status', 'PAUSED')->assertJsonPath('data.number_prefix', 'A');
    expect(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_PAUSED')->sole()->actor_id)->not->toBeNull();

    register_walkin($this->staff, guest_body($this->svc, 'Late Guest', ['guest' => ['name' => 'Late Guest']]))->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'paused');
    [, $profile2, $headers2] = remote_patient();
    register_walkin($headers2, patient_body($this->svc, $profile2))->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'paused');
    // Entries already in the queue are untouched, and can still be marked arrived.
    wi_post("{$this->remote}/arrive", $this->staff)->assertOk();

    wi_post("queues/{$queue}/open", $this->staff)->assertOk()->assertJsonPath('data.status', 'OPEN');
    expect(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_OPENED')->count())->toBe(2) // lazy creation, then the reopen
        ->and(DB::table('audit_logs')->whereIn('action', ['walkins.queue_paused', 'walkins.queue_opened'])->count())->toBe(2);
    register_walkin($headers2, patient_body($this->svc, $profile2))->assertCreated();
});

it('allows only the legal queue moves', function () {
    $queue = wi_queue_id();

    wi_post("queues/{$queue}/open", $this->staff)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'OPEN');
    wi_post("queues/{$queue}/pause", $this->staff)->assertOk();
    wi_post("queues/{$queue}/pause", $this->staff)->assertStatus(409)->assertJsonPath('errors.status.0', 'PAUSED');

    DB::table('walkin_queues')->where('id', $queue)->update(['status' => 'CLOSED', 'closed_at' => now()]);
    wi_post("queues/{$queue}/open", $this->staff)->assertStatus(409)->assertJsonPath('errors.status.0', 'CLOSED');
    wi_post("queues/{$queue}/pause", $this->staff)->assertStatus(409);
    wi_post('queues/'.Str::uuid7().'/pause', $this->staff)->assertNotFound();
});

it('lets only the location\'s staff and the queue\'s doctor pause or open a queue', function () {
    $queue = wi_queue_id();
    $second = wi_second_location($this->w);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);

    foreach ([
        'the patient' => [$this->headers, 404], 'unrelated manager' => [$other['manager'], 404], 'staff of another location' => [staff_of($this->w, Role::ClinicStaff, $second), 404],
        'admin' => [session_for(account(Role::Admin)), 403], 'support' => [session_for(account(Role::SupportAgent)), 403], 'moderator' => [session_for(account(Role::Moderator)), 404],
    ] as $who => [$headers, $status]) {
        wi_post("queues/{$queue}/pause", $headers)->assertStatus($status);
        wi_post("queues/{$queue}/open", $headers)->assertStatus($status);
    }
    expect(DB::table('walkin_queues')->value('status'))->toBe('OPEN');

    wi_post("queues/{$queue}/pause", $this->w['doctorHeaders'])->assertOk();
    wi_post("queues/{$queue}/open", $this->w['manager'])->assertOk();
});
