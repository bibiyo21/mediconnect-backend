<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Increment 5.8-3 (review C3, second half): a routine refusal over HTTP (a 409 from break or close) must write no error-level log. The
 * refusal is a client state, so Laravel's reporter skips it; a log at error or above would raise a Sentry event for a stale screen.
 */

const ROUTINE_REFUSAL_LEVELS = ['error', 'critical', 'alert', 'emergency'];

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    consultation_clock($this->clock);
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [, $this->profile, $this->patient] = remote_patient();
});

/** Runs $work and returns every log record it wrote, as [level, message] pairs. */
function rrn_logged(Closure $work): array
{
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records) {
        $records[] = [$event->level, $event->message];
    });
    $work();

    return $records;
}

/** The records at error level or above. */
function rrn_errors(array $records): array
{
    return array_values(array_filter($records, fn (array $record) => in_array($record[0], ROUTINE_REFUSAL_LEVELS, true)));
}

it('a break refused with 409 (a patient is CALLED) writes no error-level log', function () {
    walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    fresh_session();

    $records = rrn_logged(function () {
        presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])
            ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION');
    });

    expect(rrn_errors($records))->toBe([]);
});

it('a queue close refused with 409 (the doctor is checked in) writes no error-level log', function () {
    $svc = $this->w['service'];
    $remote = register_walkin($this->patient, patient_body($svc, $this->profile))->assertCreated()->json('data.id');
    $guest = register_walkin($this->staff, guest_body($svc))->assertCreated()->json('data.id');
    $queue = wi_queue_id();

    // The doctor is checked in at the location (doctor_in), so the close is refused doctor_checked_in.
    $records = rrn_logged(function () use ($queue, $remote, $guest) {
        app('auth')->forgetGuards();
        test()->postJson("/api/v1/walk-ins/queues/{$queue}/close", [
            'dispositions' => [['entry_id' => $remote, 'action' => 'CANCEL_WITH_NOTICE'], ['entry_id' => $guest, 'action' => 'TOLD_IN_PERSON']],
        ], [...$this->staff, 'Idempotency-Key' => (string) Str::uuid7()])
            ->assertStatus(409)->assertJsonPath('reason_code', 'doctor_checked_in');
    });

    expect(rrn_errors($records))->toBe([]);
});
