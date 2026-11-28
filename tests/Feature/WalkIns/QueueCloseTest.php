<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.8-2: closing a walk-in queue for the day (brief D-F). Every live entry needs a disposition; a called or in-progress entry blocks the
 * close; a doctor checked in at the location blocks it; a closed queue admits nobody and is never reopened. Nothing is cancelled without a disposition.
 */

/** Closes a queue through the API. */
function qc_close(array $headers, string $queue, array $dispositions, ?string $key = null)
{
    app('auth')->forgetGuards();

    return test()->postJson("/api/v1/walk-ins/queues/{$queue}/close", ['dispositions' => $dispositions], [...$headers, 'Idempotency-Key' => $key ?? (string) Str::uuid7()]);
}

/** The doctor checks out with HOLD, as a doctor does at the end of a shift (the queue is PAUSED, the entries kept). */
function qc_check_out(array $w): void
{
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $w['doctorHeaders'])->assertOk();
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    // The check-out reads the same pinned clock as the check-in (its closed row must not end before it starts).
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->remote = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    $this->guest = register_walkin($this->staff, guest_body($this->svc))->assertCreated()->json('data.id');
    $this->queue = wi_queue_id();
});

it('closes a queue after the doctor checked out: every live entry gets its disposition, one QUEUE_CLOSED event, an audit row', function () {
    qc_check_out($this->w);

    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertOk()->assertJsonPath('data.status', 'CLOSED');

    $queue = DB::table('walkin_queues')->where('id', $this->queue)->sole();
    expect($queue->status)->toBe('CLOSED')->and($queue->closed_by)->not->toBeNull()->and($queue->closed_at)->not->toBeNull();
    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('status'))->toBe('CANCELLED')
        ->and(DB::table('walkin_queue_entries')->where('id', $this->guest)->value('status'))->toBe('CANCELLED');

    // The patient is told in the app; the guest is not (no channel), and the staff confirmation is on the guest's event.
    $notice = DB::table('notifications')->where('type', 'walkins.cancelled')->sole();
    expect($notice->notifiable_id)->toBe($this->user->id);
    $cancelled = DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->orderBy('id')->get();
    expect($cancelled)->toHaveCount(2)
        ->and(json_decode($cancelled[0]->metadata, true))->toEqual(['by' => 'STAFF'])
        ->and(json_decode($cancelled[1]->metadata, true))->toEqual(['by' => 'STAFF', 'patient_informed_in_person' => true])
        ->and($cancelled->pluck('reason')->unique()->all())->toBe(['DOCTOR_CHECKED_OUT']);
    expect(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_CLOSED')->where('walkin_queue_id', $this->queue)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'walkins.queue_closed')->count())->toBe(1);
});

it('refuses a close that leaves a live entry without a disposition, names it, and changes nothing (422)', function () {
    qc_check_out($this->w);

    qc_close($this->staff, $this->queue, [['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE']])
        ->assertStatus(422)->assertJsonPath('errors.dispositions.0', $this->guest);

    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('PAUSED')
        ->and(DB::table('walkin_queue_entries')->where('status', 'CANCELLED')->count())->toBe(0);
});

it('refuses a guest given CANCEL_WITH_NOTICE, and an entry id that is not live in this queue (422)', function () {
    qc_check_out($this->w);

    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'CANCEL_WITH_NOTICE'],
    ])->assertStatus(422);

    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
        ['entry_id' => '018f0000-0000-7000-8000-000000000001', 'action' => 'TOLD_IN_PERSON'],
    ])->assertStatus(422);
    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('PAUSED');
});

it('refuses to close while a patient is called (409 ACTIVE_CONSULTATION, reason called, with the entry id)', function () {
    qc_check_out($this->w);
    DB::table('walkin_queue_entries')->where('id', $this->remote)->update([
        'status' => 'CALLED', 'called_at' => now(), 'assigned_doctor_profile_id' => $this->w['doctor']->id,
    ]);

    qc_close($this->staff, $this->queue, [['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON']])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'called')->assertJsonPath('errors.entry_id.0', $this->remote);
    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('PAUSED');
});

it('refuses to close while the doctor is checked in at the location (409 INVALID_STATE_TRANSITION, doctor_checked_in)', function () {
    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'], ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('reason_code', 'doctor_checked_in');

    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('OPEN')
        ->and(DB::table('walkin_queue_entries')->where('status', 'CANCELLED')->count())->toBe(0);
});

it('a closed queue is never reopened, cannot be closed again, and admits no walk-ins', function () {
    qc_check_out($this->w);
    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'], ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertOk();

    qc_close($this->staff, $this->queue, [])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'CLOSED');
    wi_post("queues/{$this->queue}/open", $this->staff)->assertStatus(409)->assertJsonPath('errors.status.0', 'CLOSED');
    // The doctor is back in today: the refusal is the closed queue itself (QUEUE_CLOSED, closed), not the doctor's presence.
    checkin($this->w['doctorHeaders'], $this->w['location'])->assertSuccessful();
    register_walkin($this->staff, guest_body($this->svc, 'Late Guest'))->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'closed');
    expect(DB::table('walkin_queue_entries')->where('walkin_queue_id', $this->queue)->where('status', 'WAITING')->count())->toBe(0);
});

it('a patient given TOLD_IN_PERSON is cancelled with no notice (only CANCEL_WITH_NOTICE notifies)', function () {
    qc_check_out($this->w);
    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'TOLD_IN_PERSON'], ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertOk();

    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('status'))->toBe('CANCELLED')
        ->and(DB::table('notifications')->where('type', 'walkins.cancelled')->count())->toBe(0);
});

it('replays the same answer for the same key, and needs a key (400)', function () {
    qc_check_out($this->w);
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/queues/{$this->queue}/close", ['dispositions' => []], $this->staff)->assertStatus(400);

    $key = (string) Str::uuid7();
    $body = [['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'], ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON']];
    qc_close($this->staff, $this->queue, $body, $key)->assertOk();
    qc_close($this->staff, $this->queue, $body, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_CLOSED')->count())->toBe(1);
});

it('keeps the close to the location: another clinic\'s staff get 404, a patient gets 404, an admin 403', function () {
    qc_check_out($this->w);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);

    qc_close(staff_of($other), $this->queue, [])->assertStatus(404);
    qc_close($this->headers, $this->queue, [])->assertStatus(404);
    qc_close(session_for(account(Role::Admin)), $this->queue, [])->assertStatus(403);
    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('PAUSED');
});

it('the partial index for a doctor\'s CALLED and IN_CONSULTATION entries exists (carry-in HAG-39)', function () {
    $def = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'ix_entry_doctor_active'");
    expect($def)->not->toBeNull()
        ->and($def->indexdef)->toContain('assigned_doctor_profile_id')
        ->and($def->indexdef)->toContain('CALLED')
        ->and($def->indexdef)->toContain('IN_CONSULTATION');
});

it('a doctor who was never checked in today gives the close reason OTHER', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $guest = register_walkin(staff_of($other), guest_body($other['service'], 'Only Guest'))->assertCreated()->json('data.id');
    $queue = (string) DB::table('walkin_queues')->where('clinic_location_id', $other['location'])->value('id');

    qc_close(staff_of($other), $queue, [['entry_id' => $guest, 'action' => 'TOLD_IN_PERSON']])->assertOk();

    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $guest)->where('event_type', 'CANCELLED')->value('reason'))->toBe('OTHER');
});

it('a past-day queue closes with 200 while the doctor is checked in today, and the reason is OTHER', function () {
    DB::table('walkin_queues')->where('id', $this->queue)->update(['local_date' => $this->clock->now()->subDay()->toDateString()]);

    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'], ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertOk()->assertJsonPath('data.status', 'CLOSED');

    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('CLOSED')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->pluck('reason')->unique()->all())->toBe(['OTHER']);
});

it('refuses to close while a consultation is in progress (409 ACTIVE_CONSULTATION, reason in_progress, with the entry id)', function () {
    qc_check_out($this->w);
    DB::table('walkin_queue_entries')->where('id', $this->remote)->update([
        'status' => 'IN_CONSULTATION', 'called_at' => now(), 'consultation_started_at' => now(), 'assigned_doctor_profile_id' => $this->w['doctor']->id,
    ]);

    qc_close($this->staff, $this->queue, [['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON']])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'in_progress')->assertJsonPath('errors.entry_id.0', $this->remote);
    expect(DB::table('walkin_queues')->where('id', $this->queue)->value('status'))->toBe('PAUSED')
        ->and(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('status'))->toBe('IN_CONSULTATION');
});

it('closes a SKIPPED entry with its disposition: the entry is CANCELLED and its care relationship is REVOKED', function () {
    qc_check_out($this->w);
    DB::table('walkin_queue_entries')->where('id', $this->remote)->update(['status' => 'SKIPPED', 'assigned_doctor_profile_id' => null]);
    $grantId = care_grant($this->remote, $this->clock->now());
    expect($grantId)->not->toBeNull();

    qc_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'], ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertOk()->assertJsonPath('data.status', 'CLOSED');

    expect(DB::table('walkin_queue_entries')->where('id', $this->remote)->value('status'))->toBe('CANCELLED')
        ->and(DB::table('care_relationships')->where('id', $grantId)->value('status'))->toBe('REVOKED')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $this->remote)->where('event_type', 'CANCELLED')->value('from_status'))->toBe('SKIPPED')
        ->and(DB::table('notifications')->where('type', 'walkins.cancelled')->where('notifiable_id', $this->user->id)->count())->toBe(1);
});
