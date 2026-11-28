<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.8-2: transfer of one live entry to another queue of the same location and day (brief D-G). The entry keeps its id and status; it
 * takes the next number of the target; staff only; the target doctor's admission rules apply; no place is duplicated; capacity is respected.
 */

/** Moves an entry through the API. */
function qt_transfer(array $headers, string $entry, string $target, array $extra = [])
{
    return wi_post("{$entry}/transfer", $headers, ['walkin_queue_id' => $target, ...$extra]);
}

/** A live entry for a registered patient straight into a queue (staff cannot register a patient profile through the staff path). */
function qt_insert_entry(string $queue, string $profile, string $service, string $registeredBy, string $status = 'WAITING'): string
{
    $id = (string) Str::uuid7();
    $number = (int) DB::table('walkin_queues')->where('id', $queue)->value('last_number') + 1;
    DB::table('walkin_queues')->where('id', $queue)->update(['last_number' => $number]);
    DB::table('walkin_queue_entries')->insert([
        'id' => $id, 'walkin_queue_id' => $queue, 'queue_number' => $number, 'patient_profile_id' => $profile, 'registered_by_user_id' => $registeredBy,
        'registration_channel' => 'PATIENT_APP', 'status' => $status, 'payer_type' => 'SELF_PAY', 'doctor_service_id' => $service,
        'arrived_at' => now(), 'queue_sort_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->remote = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    wi_post("{$this->remote}/arrive", $this->staff)->assertOk();
    $this->source = wi_queue_id();

    // A second doctor at the same location: a guest registered for her creates her queue for today.
    $this->second = wi_second_doctor($this->w);
    $this->targetGuest = register_walkin($this->staff, guest_body($this->second['service'], 'Waiting Guest'))->assertCreated()->json('data.id');
    $this->target = (string) DB::table('walkin_queues')->where('doctor_profile_id', $this->second['doctor']->id)->value('id');
});

it('moves a WAITING entry to the target queue: same id and status, the next number there, a TRANSFERRED event and the patient\'s notice', function () {
    $r = qt_transfer($this->staff, $this->remote, $this->target)->assertOk()->assertJsonPath('data.status', 'WAITING');

    expect($r->json('data.id'))->toBe($this->remote);
    $entry = DB::table('walkin_queue_entries')->where('id', $this->remote)->sole();
    expect($entry->walkin_queue_id)->toBe($this->target)->and($entry->queue_number)->toBe(2)->and($entry->status)->toBe('WAITING')
        ->and($entry->doctor_service_id)->toBe($this->second['service'])->and($entry->assigned_doctor_profile_id)->toBeNull()
        ->and(DB::table('walkin_queues')->where('id', $this->target)->value('last_number'))->toBe(2);

    // The history stays on the source queue; the transfer is on the target.
    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $this->remote)->where('event_type', 'ARRIVED')->value('walkin_queue_id'))->toBe($this->source);
    $moved = DB::table('walkin_queue_events')->where('event_type', 'TRANSFERRED')->sole();
    expect($moved->walkin_queue_id)->toBe($this->target)->and($moved->walkin_queue_entry_id)->toBe($this->remote)
        ->and(json_decode($moved->metadata, true))->toEqual(['from_queue_id' => $this->source, 'from_number' => 1, 'to_number' => 2, 'capacity_override' => false]);

    $notice = DB::table('notifications')->where('type', 'walkins.transferred')->sole();
    expect($notice->notifiable_id)->toBe($this->user->id)->and(json_decode($notice->data, true)['body'])->toBe('Open the app for your new number.');
    expect(DB::table('audit_logs')->where('action', 'walkins.transferred')->count())->toBe(1);
});

it('refuses an entry that is CALLED or SKIPPED (409 INVALID_STATE_TRANSITION, with the status)', function () {
    DB::table('walkin_queue_entries')->where('id', $this->remote)->update(['status' => 'SKIPPED']);
    qt_transfer($this->staff, $this->remote, $this->target)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'SKIPPED');

    DB::table('walkin_queue_entries')->where('id', $this->remote)->update([
        'status' => 'CALLED', 'called_at' => now(), 'assigned_doctor_profile_id' => $this->w['doctor']->id,
    ]);
    qt_transfer($this->staff, $this->remote, $this->target)->assertStatus(409)->assertJsonPath('errors.status.0', 'CALLED');
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('walkin_queue_id'))->toBe($this->source);
});

it('refuses a paused target (409 QUEUE_CLOSED, reason paused)', function () {
    wi_post("queues/{$this->target}/pause", $this->staff)->assertOk();

    qt_transfer($this->staff, $this->remote, $this->target)->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'paused');
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('walkin_queue_id'))->toBe($this->source);
});

it('refuses a patient who already holds a place in the target (409 ENTRY_ALREADY_LIVE, with that entry)', function () {
    $existing = qt_insert_entry($this->target, $this->profile, $this->second['service'], $this->user->id);

    qt_transfer($this->staff, $this->remote, $this->target)->assertStatus(409)->assertJsonPath('code', 'ENTRY_ALREADY_LIVE')->assertJsonPath('errors.entry_id.0', $existing);
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('walkin_queue_id'))->toBe($this->source);
});

it('refuses a target doctor who has checked out (409 DOCTOR_NOT_AVAILABLE, checked_out)', function () {
    checkin($this->second['headers'], $this->w['location'])->assertSuccessful();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $this->second['headers'])->assertOk();

    qt_transfer($this->staff, $this->remote, $this->target)->assertStatus(409)->assertJsonPath('code', 'DOCTOR_NOT_AVAILABLE')->assertJsonPath('reason_code', 'checked_out');
});

it('respects the target\'s capacity: CAPACITY_OVERRIDE_REQUIRED, then an acknowledged override moves the entry and is audited', function () {
    // The capacity comes from the schedule (as registration derives it), so the schedule carries the limit.
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 1]);

    qt_transfer($this->staff, $this->remote, $this->target)->assertStatus(409)->assertJsonPath('code', 'CAPACITY_OVERRIDE_REQUIRED');
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('walkin_queue_id'))->toBe($this->source);

    qt_transfer($this->staff, $this->remote, $this->target, ['over_capacity_ack' => true])->assertOk();
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('walkin_queue_id'))->toBe($this->target)
        ->and(DB::table('audit_logs')->where('action', 'walkins.capacity_overridden')->count())->toBe(1);
});

it('stays inside the clinic: another clinic\'s queue is 404 for this staff, the same queue is 422, a doctor is 403', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    register_walkin(staff_of($other), guest_body($other['service'], 'Other Guest'))->assertCreated();
    $otherQueue = (string) DB::table('walkin_queues')->where('clinic_location_id', $other['location'])->value('id');

    qt_transfer($this->staff, $this->remote, $otherQueue)->assertStatus(404);
    qt_transfer($this->staff, $this->remote, $this->source)->assertStatus(422);
    qt_transfer($this->w['doctorHeaders'], $this->remote, $this->target)->assertStatus(403);
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('walkin_queue_id'))->toBe($this->source);
});

it('the events table refuses an event that names a queue other than its entry\'s current one', function () {
    qt_transfer($this->staff, $this->remote, $this->target)->assertOk();

    // A savepoint, so the refusal does not end the test transaction.
    expect(fn () => DB::transaction(fn () => DB::table('walkin_queue_events')->insert([
        'walkin_queue_id' => $this->source, 'walkin_queue_entry_id' => $this->remote, 'event_type' => 'ARRIVED',
        'from_status' => 'WAITING', 'to_status' => 'WAITING', 'actor_id' => $this->staff['_user_id'] ?? null, 'reason' => null, 'metadata' => null,
    ])))->toThrow(QueryException::class, 'walk-in event does not name the current queue of its entry');

    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $this->remote)->where('walkin_queue_id', $this->source)->count())->toBe(2);
});

it('takes the capacity the schedule gives, not a stored number that is stale (registration\'s formula): an unlimited schedule admits without an override', function () {
    DB::table('walkin_queues')->where('id', $this->target)->update(['capacity' => 1]);

    qt_transfer($this->staff, $this->remote, $this->target)->assertOk();
    expect(DB::table('audit_logs')->where('action', 'walkins.capacity_overridden')->count())->toBe(0);
});
