<?php

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Notifications\Jobs\SendPushNotification;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Increment 5.8-3 (D-O, owner OQ-A): the daily sweep of an earlier day's queue. Live entries (REGISTERED, WAITING, SKIPPED) are cancelled
 * with END_OF_DAY by the system, in-app notice only. A CALLED or IN_CONSULTATION entry is never touched: the queue stays open and an alert
 * is logged. Today's queue is never swept.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    $this->doctorId = (string) $this->w['doctor']->id;
});

it('cancels a waiting entry of an earlier day with END_OF_DAY, notifies in-app only, and closes the queue', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    Queue::fake([SendPushNotification::class]); // after the arrival: only the push is faked, the auto check-out job still runs

    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(9, 0)->utc();
    // The forgotten Monday session is closed first (5.8 m4: a sweep waits for the auto check-out), HOLD only.
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('CANCELLED');
    expect(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('CLOSED');
    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'CANCELLED')->value('reason'))->toBe(CancelReason::EndOfDay->value)
        ->and(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_CLOSED')->value('actor_id'))->toBeNull();
    expect(DB::table('notifications')->where('notifiable_id', $this->user->id)->where('type', 'walkins.cancelled')->count())->toBe(1);
    Queue::assertNotPushed(SendPushNotification::class, fn (SendPushNotification $job) => DB::table('notifications')->where('id', $job->notificationId)->value('type') === 'walkins.cancelled');
});

it('never touches a CALLED entry: the queue stays open, an alert is logged, and the second run changes nothing', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    // The session began on Sunday, so it is not the queue's own day and the sweep does not wait for it (5.8 m4).
    DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->update(['checked_in_at' => $this->clock->at->subDay()]);
    Log::spy();

    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(9, 0)->utc();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('CALLED')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->not->toBe('CLOSED');
    Log::shouldHaveReceived('warning')->with('PAST_DAY_QUEUE_NEEDS_ATTENTION', Mockery::on(fn (array $ctx) => $ctx['called'] === 1 && $ctx['in_consultation'] === 0))->once();

    $this->artisan('walkins:close-past-days')->expectsOutput('walkins:close-past-days closed 0 queue(s)')->assertSuccessful();
    expect(wi_status($entry))->toBe('CALLED');
});

it('leaves today\'s queue alone', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);

    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('WAITING')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('OPEN');
});

it('skips a queue while its doctor is still IN from that same day (past midnight), and sweeps after the auto check-out (5.8 m4)', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    Log::spy();

    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(0, 15)->utc();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('WAITING')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('OPEN');
    Log::shouldHaveReceived('info')->with('PAST_DAY_QUEUE_SWEEP_SKIPPED', Mockery::on(fn (array $ctx) => $ctx['reason_code'] === 'doctor_still_in'
        && array_keys($ctx) === ['walkin_queue_id', 'doctor_presence_session_id', 'clinic_location_id', 'reason_code']))->once();

    // The automatic check-out closes the Monday session (HOLD only), then the sweep runs on the next pass.
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('CANCELLED')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('CLOSED');
});

it('keeps sweeping the other queues when one queue fails, and logs its id and the exception class only (5.8 m3)', function () {
    $failing = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    $failingQueue = wi_queue_id();
    [$healthyQueue, $healthyEntry] = wi_second_queue_for_doctor($this->w, 'WAITING');
    // Both queues belong to earlier days. The failing one is the first in id order, so the sweep reaches the healthy one after it fails.
    DB::table('walkin_queues')->where('id', $failingQueue)->update(['local_date' => $this->clock->at->setTimezone('Asia/Manila')->subDays(2)->toDateString()]);
    DB::table('walkin_queues')->where('id', $healthyQueue)->update(['local_date' => $this->clock->at->setTimezone('Asia/Manila')->subDays(3)->toDateString()]);
    expect($failingQueue < $healthyQueue)->toBeTrue();

    // Fail only the close of the first queue: the audit write for that queue throws inside its transaction, which then rolls back.
    $real = app(RecordsAudit::class);
    app()->instance(RecordsAudit::class, new class($real, $failingQueue) implements RecordsAudit
    {
        public function __construct(private readonly RecordsAudit $inner, private readonly string $failing) {}

        public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $changes = [], bool $accessEvent = false, ?string $clinicId = null): void
        {
            if ($action === 'walkins.queue_closed' && $subjectId === $this->failing) {
                throw new RuntimeException('simulated audit failure');
            }
            $this->inner->record($action, $subjectType, $subjectId, $changes, $accessEvent, $clinicId);
        }
    });

    Log::spy();
    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(9, 0)->utc();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(DB::table('walkin_queues')->where('id', $healthyQueue)->value('status'))->toBe('CLOSED')
        ->and(wi_status($healthyEntry))->toBe('CANCELLED');
    expect(DB::table('walkin_queues')->where('id', $failingQueue)->value('status'))->not->toBe('CLOSED')
        ->and(wi_status($failing))->toBe('WAITING');
    Log::shouldHaveReceived('error')->with('PAST_DAY_QUEUE_SWEEP_FAILED', ['walkin_queue_id' => $failingQueue, 'exception' => RuntimeException::class])->once();
});

it('reads the queue day on the location\'s own clock, not Manila\'s (5.8 M3 remainder)', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile); // queue day: Monday (Manila)
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['timezone' => 'America/New_York']);

    // Tuesday 01:00 Manila is still Monday 12:00 in New York: the queue is not yet an earlier day there, so nothing is swept.
    $tuesday = $this->clock->at->setTimezone('Asia/Manila')->startOfDay()->addDay();
    $this->clock->at = $tuesday->setTime(1, 0)->utc();
    $this->artisan('walkins:close-past-days')->assertSuccessful();
    expect(wi_status($entry))->toBe('WAITING')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('OPEN');

    // Tuesday 10:00 in New York: the Monday queue is an earlier day there. The forgotten session is closed first.
    $this->clock->at = CarbonImmutable::parse($tuesday->toDateString().' 10:00', 'America/New_York')->utc();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $this->artisan('walkins:close-past-days')->assertSuccessful();
    expect(wi_status($entry))->toBe('CANCELLED')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('CLOSED');
});

it('cancels a SKIPPED entry of an earlier day with END_OF_DAY (5.8 M3 remainder)', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    wi_post("{$entry}/skip", $this->staff)->assertOk();
    expect(wi_status($entry))->toBe('SKIPPED');

    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(9, 0)->utc();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('CANCELLED')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'CANCELLED')->value('reason'))->toBe(CancelReason::EndOfDay->value)
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('CLOSED');
});

it('cancels a guest entry of an earlier day with END_OF_DAY (5.8 M3 remainder)', function () {
    $entry = wi_guest($this->staff, $this->w['service']);

    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(9, 0)->utc();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('CANCELLED')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'CANCELLED')->value('reason'))->toBe(CancelReason::EndOfDay->value)
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('CLOSED');
});

it('never touches an IN_CONSULTATION entry: the queue stays open, an alert counts it, and the second run changes nothing (5.8 M3 remainder)', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    consultation_clock($this->clock); // the consultation start reads the same pinned day as the queue
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    // The session began on Sunday, so it is not the queue's own day and the sweep does not wait for it.
    DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->update(['checked_in_at' => $this->clock->at->subDay()]);
    Log::spy();

    $this->clock->at = $this->clock->at->addDay()->setTimezone('Asia/Manila')->setTime(9, 0)->utc();
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('IN_CONSULTATION')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->not->toBe('CLOSED');
    Log::shouldHaveReceived('warning')->with('PAST_DAY_QUEUE_NEEDS_ATTENTION', Mockery::on(fn (array $ctx) => $ctx['called'] === 0 && $ctx['in_consultation'] === 1))->once();

    $this->artisan('walkins:close-past-days')->expectsOutput('walkins:close-past-days closed 0 queue(s)')->assertSuccessful();
    expect(wi_status($entry))->toBe('IN_CONSULTATION');
});
