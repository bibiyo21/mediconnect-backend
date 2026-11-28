<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\WalkIns\Events\WalkInEntryUpdated;
use App\Modules\WalkIns\Listeners\FanOutOnPresenceChange;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Increment 5.8-2: presence-change fan-out (brief D-M, 5.7 OI-1). A presence change that commits (check-in, break, check-out) hints the live
 * entries of the doctor's queues at that location for today, so their estimates are re-read. A refused or no-op change hints nothing, and a
 * doctor with no queue today hints no one else.
 */

/** The entry-hint broadcasts sent for one entry (the audience is resolved at send time; one per account). */
function pf_hints(string $entryId): int
{
    return Queue::pushed(BroadcastEvent::class)->filter(function ($job) use ($entryId) {
        return $job->event instanceof WalkInEntryUpdated && $job->event->broadcastWith()['entry_id'] === $entryId;
    })->count();
}

beforeEach(function () {
    Queue::fake();
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    app()->when(FanOutOnPresenceChange::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->entry = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    config(['queue.default' => 'redis']); // the trailing round is only scheduled off the sync queue
    Cache::flush();
    Queue::fake(); // forget the set-up traffic
});

it('hints the live patient when the doctor starts a break', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/start', ['clinic_location_id' => $this->w['location'], 'expected_minutes' => 10], $this->w['doctorHeaders'])->assertOk();

    expect(pf_hints($this->entry))->toBe(1);
});

it('hints the live patient when the doctor checks out, and the check-out itself stays OUT', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $this->w['doctorHeaders'])->assertOk();

    expect(pf_hints($this->entry))->toBeGreaterThanOrEqual(1);
    expect(DB::table('walkin_queue_entries')->where('id', $this->entry)->value('status'))->toBe('REGISTERED');
});

it('sends nothing for a change that is a no-op (a repeat check-in at the same place)', function () {
    doctor_in($this->w);

    expect(pf_hints($this->entry))->toBe(0);
});

it('a doctor with no queue today hints no one in another doctor\'s queue', function () {
    $second = wi_second_doctor($this->w);
    checkin($second['headers'], $this->w['location'])->assertSuccessful();

    expect(pf_hints($this->entry))->toBe(0);
});
