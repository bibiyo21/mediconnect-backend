<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.8-T2: queue close security (brief §4 5.8-T2). Who may close a queue (the location's staff, or the queue's own doctor while the
 * doctor still works at the location), what a close may cancel (a guest is never given CANCEL_WITH_NOTICE), and that a refused close changes
 * nothing. Authorisation refusals are checked by status code and by the rows that must stay unchanged.
 */

/** Closes a queue through the API, with a fresh Idempotency-Key. */
function qcs_close(array $headers, string $queue, array $dispositions)
{
    app('auth')->forgetGuards();

    return test()->postJson("/api/v1/walk-ins/queues/{$queue}/close", ['dispositions' => $dispositions], [...$headers, 'Idempotency-Key' => (string) Str::uuid7()]);
}

/** The queue's state and its live entries' statuses, for the "refused close changes nothing" assertions. */
function qcs_snapshot(string $queue): array
{
    return [
        'queue' => DB::table('walkin_queues')->where('id', $queue)->value('status'),
        'entries' => DB::table('walkin_queue_entries')->where('walkin_queue_id', $queue)->orderBy('id')->pluck('status')->all(),
        'closed_events' => DB::table('walkin_queue_events')->where('event_type', 'QUEUE_CLOSED')->count(),
    ];
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->remote = register_walkin($this->headers, patient_body($this->w['service'], $this->profile))->assertCreated()->json('data.id');
    $this->guest = register_walkin($this->staff, guest_body($this->w['service'], 'Close Guest'))->assertCreated()->json('data.id');
    $this->queue = wi_queue_id();

    // The doctor checks out with HOLD, so the queue is PAUSED and the close is allowed to run.
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $this->w['doctorHeaders'])->assertOk();
});

it('a clinic staff member restricted to another location cannot close this location\'s queue (404), and nothing changes', function () {
    $restricted = clinic_member($this->w['clinic'], Role::ClinicStaff, wi_second_location($this->w))[1];
    $before = qcs_snapshot($this->queue);

    qcs_close($restricted, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertStatus(404);

    expect(qcs_snapshot($this->queue))->toBe($before);
});

it('a doctor of another clinic cannot close this queue (404), and nothing changes', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $before = qcs_snapshot($this->queue);

    qcs_close($other['doctorHeaders'], $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertStatus(404);

    expect(qcs_snapshot($this->queue))->toBe($before);
});

it('the queue\'s own doctor, whose assignment at this location has ended, cannot close the queue (404), and nothing changes', function () {
    // Known gap (brief §9 L1, card): the DOCTOR channel checks only that the caller owns the queue's doctor profile, not an ACTIVE assignment.
    DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $this->w['doctor']->id)->where('clinic_location_id', $this->w['location'])->update(['status' => 'ENDED', 'effective_until' => now()->toDateString()]);
    $before = qcs_snapshot($this->queue);

    qcs_close($this->w['doctorHeaders'], $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'TOLD_IN_PERSON'],
    ])->assertStatus(404);

    expect(qcs_snapshot($this->queue))->toBe($before);
});

it('a guest given CANCEL_WITH_NOTICE is refused (422) even when the patient entry is valid, and the close changes nothing', function () {
    $before = qcs_snapshot($this->queue);

    qcs_close($this->staff, $this->queue, [
        ['entry_id' => $this->remote, 'action' => 'CANCEL_WITH_NOTICE'],
        ['entry_id' => $this->guest, 'action' => 'CANCEL_WITH_NOTICE'],
    ])->assertStatus(422);

    expect(qcs_snapshot($this->queue))->toBe($before)
        ->and(DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->count())->toBe(0);
});
