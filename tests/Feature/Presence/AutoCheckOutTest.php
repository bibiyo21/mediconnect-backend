<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\Presence\Exceptions\InvalidPresenceTransition;
use App\Modules\Presence\Jobs\AutoCheckOut;
use App\Modules\Presence\Services\PresenceActor;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Increment 5.8-3 (D-L): the automatic check-out. A SYSTEM actor checks out a forgotten session after the clinic's closing plus the grace
 * (60 minutes by default), or from an earlier day. It holds the walk-ins (never cancels), refuses to touch a consultation or a called patient
 * (skipped and logged with ids and the reason code only), and is idempotent.
 */

/** The doctor's open session status at the world's location (null when none). */
function ac_status(string $doctorId): ?string
{
    return DB::table('doctor_presence_sessions')->where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')->value('current_status');
}

/** Moves the pinned clock to a Manila wall time on the given day offset from the pinned Monday. */
function ac_at(PinnedClock $clock, int $dayOffset, int $hour, int $minute = 0): void
{
    $clock->at = $clock->at->setTimezone('Asia/Manila')->startOfDay()->addDays($dayOffset)->setTime($hour, $minute)->utc();
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    app()->when(AutoCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    $this->doctorId = (string) $this->w['doctor']->id;
});

it('checks a forgotten doctor out after the clinic closes plus the grace, as SYSTEM: HOLD only, nothing cancelled', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    expect(ac_status($this->doctorId))->toBe('IN');

    // Clinic closes at 17:00 Manila; the grace is 60 minutes, so 18:00 is the limit.
    ac_at($this->clock, 0, 17, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBe('IN');

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    $session = DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->sole();
    expect($session->current_status)->toBe('OUT')
        ->and($session->checkout_reason)->toBe('CLINIC_CLOSED')
        ->and($session->checked_out_by)->toBeNull()
        ->and($session->checked_out_method)->toBe('SYSTEM');
    expect(DB::table('doctor_presence_events')->where('new_status', 'OUT')->value('changed_by'))->toBeNull()
        ->and(DB::table('doctor_presence_events')->where('new_status', 'OUT')->value('change_method'))->toBe('SYSTEM');
    expect(wi_status($entry))->toBe('WAITING')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('PAUSED');
});

it('checks out a session that was checked in on an earlier day, at any hour', function () {
    ac_at($this->clock, 1, 9, 0); // Tuesday morning: the doctor is still IN from Monday
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull()
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('checkout_reason'))->toBe('CLINIC_CLOSED');
});

it('skips a session with a consultation in progress, logs the reason code only, and retries on the next run', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    Log::spy();

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBe('IN')->and(wi_status($entry))->toBe('IN_CONSULTATION');
    Log::shouldHaveReceived('info')->with('PRESENCE_AUTO_CHECKOUT_SKIPPED', Mockery::on(fn (array $ctx) => $ctx['reason_code'] === 'in_progress'
        && array_keys($ctx) === ['doctor_presence_session_id', 'doctor_profile_id', 'clinic_location_id', 'reason_code']))->once();

    // The consultation ends; the next run closes the session.
    act_post("/api/v1/consultations/{$consultation}/end", $this->w['doctorHeaders'])->assertOk();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBeNull();
});

it('skips a session with a patient still called to the doctor (reason called), and cancels nobody', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    Log::spy();

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBe('IN')->and(wi_status($entry))->toBe('CALLED');
    Log::shouldHaveReceived('info')->with('PRESENCE_AUTO_CHECKOUT_SKIPPED', Mockery::on(fn (array $ctx) => $ctx['reason_code'] === 'called'))->once();
});

it('is idempotent: a second run over a closed session writes nothing more', function () {
    ac_at($this->clock, 1, 9, 0);
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    $events = DB::table('doctor_presence_events')->count();

    $this->artisan('presence:auto-checkout')->expectsOutput('presence:auto-checkout queued 0')->assertSuccessful();
    expect(DB::table('doctor_presence_events')->count())->toBe($events);
});

it('closes an earlier-day session without flagging today\'s appointments, and tells the doctor (5.8 M1, D-H)', function () {
    $tuesdayTen = $this->clock->at->setTimezone('Asia/Manila')->startOfDay()->addDay()->setTime(10, 0)->utc();
    wi_appointment($this->w, $tuesdayTen, $tuesdayTen->addMinutes(20));

    ac_at($this->clock, 1, 0, 5); // Tuesday 00:05: the Monday session is still IN
    [$tuesdayQueue, $tuesdayEntry] = wi_second_queue_for_doctor($this->w, 'WAITING'); // today's (Tuesday) queue, created before the run
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull()
        ->and(DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->value('needs_rescheduling'))->toBeFalse()
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0)
        ->and(DB::table('walkin_queues')->where('id', $tuesdayQueue)->value('status'))->toBe('OPEN')
        ->and(wi_status($tuesdayEntry))->toBe('WAITING');
    expect(DB::table('notifications')->where('notifiable_id', $this->w['doctor']->user_id)->where('type', 'presence.auto_checked_out')->count())->toBe(1);
});

it('notifies the doctor with generic text only, and records the audit row with no acting user (5.8 D-H, D-K)', function () {
    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    $notice = DB::table('notifications')->where('notifiable_id', $this->w['doctor']->user_id)->where('type', 'presence.auto_checked_out')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->data)->toContain('You were checked out automatically')
        ->and($notice->data)->toContain('Open the app to review your clinic status.');
    expect($notice->data)->not->toContain('CLINIC_CLOSED')->and($notice->data)->not->toContain('Santos');

    $audit = DB::table('audit_logs')->where('action', 'presence.checked_out')->first();
    expect($audit)->not->toBeNull()->and($audit->actor_user_id)->toBeNull();
});

it('skips a session with an interrupted consultation (reason interrupted) and closes it once ended', function () {
    walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    act_post("/api/v1/consultations/{$consultation}/interrupt", $this->w['doctorHeaders'])->assertOk();
    Log::spy();

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBe('IN');
    Log::shouldHaveReceived('info')->with('PRESENCE_AUTO_CHECKOUT_SKIPPED', Mockery::on(fn (array $ctx) => $ctx['reason_code'] === 'interrupted'))->once();
});

it('uses the configured grace from the walk-in policy and keeps it across other policy edits (5.8 m2)', function () {
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['auto_checkout_grace_minutes' => 0])->assertOk();
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['auto_checkout_grace_minutes' => 721])->assertUnprocessable();
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['allow_patient_app_registration' => false])->assertOk();

    ac_at($this->clock, 0, 17, 30); // closing 17:00 with a zero grace
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull();
});

it('still checks out a session whose assignment has ended, with the default grace (5.8 m1)', function () {
    DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $this->doctorId)->update(['status' => 'ENDED', 'effective_until' => CarbonImmutable::now('Asia/Manila')->toDateString()]);

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull();
});

it('refuses an automatic check-out aimed at a session that was closed and reopened meanwhile (5.8 m7)', function () {
    $old = (string) DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->whereNull('checked_out_at')->value('id');
    app(PresenceCheckOut::class)->checkOut(PresenceActor::system(), $this->doctorId, $this->w['location'], CheckOutReason::ClinicClosed, CheckOutDisposition::HoldForReassignment, false);
    doctor_in($this->w);
    $new = (string) DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->whereNull('checked_out_at')->value('id');
    expect($new)->not->toBe($old);

    expect(fn () => app(PresenceCheckOut::class)->checkOut(PresenceActor::system(), $this->doctorId, $this->w['location'], CheckOutReason::ClinicClosed, CheckOutDisposition::HoldForReassignment, false, $old))
        ->toThrow(InvalidPresenceTransition::class);
    expect(DB::table('doctor_presence_sessions')->where('id', $new)->value('current_status'))->toBe('IN');
});

it('takes the grace from the location wall clock across a daylight-saving change (5.8 M3)', function () {
    // America/New_York moves to daylight time at 02:00 on Sunday 2027-03-14. The clinic closes 17:00 local (EDT, UTC-4, 21:00 UTC) and the
    // grace is 60 minutes, so the limit is 18:00 local (22:00 UTC). A fixed UTC-5 offset would move the limit to 23:00 UTC.
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['timezone' => 'America/New_York']);
    DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->w['location'], 'day_of_week' => 7, 'opens_at' => '08:00', 'closes_at' => '17:00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->whereNull('checked_out_at')
        ->update(['checked_in_at' => CarbonImmutable::parse('2027-03-14 08:00', 'America/New_York')->utc()]);

    $this->clock->at = CarbonImmutable::parse('2027-03-14 17:30', 'America/New_York')->utc();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBe('IN');

    $this->clock->at = CarbonImmutable::parse('2027-03-14 18:30', 'America/New_York')->utc();
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBeNull();
});

it('keeps a doctor on special hours past the clinic close checked in until the last booking ends plus the grace (5.8 V-M1)', function () {
    // Clinic closes 17:00; the doctor has special hours 17:00-21:00 with a booking 20:40-21:00. Limit = 21:00 + 60 = 22:00.
    $day = $this->clock->at->setTimezone('Asia/Manila')->startOfDay();
    DB::table('doctor_schedule_exceptions')->insert(['id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctorId, 'clinic_location_id' => $this->w['location'],
        'doctor_service_mode_id' => $this->w['mode'], 'exception_type' => 'SPECIAL_HOURS', 'effect' => 'ADD_HOURS', 'priority' => 4,
        'starts_at' => $day->setTime(17, 0)->utc(), 'ends_at' => $day->setTime(21, 0)->utc(), 'requested_by' => $this->w['doctor']->user_id, 'status' => 'ACTIVE']);
    wi_appointment($this->w, $day->setTime(20, 40)->utc(), $day->setTime(21, 0)->utc());

    ac_at($this->clock, 0, 18, 5); // past 17:00 + 60 but inside the special hours
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBe('IN')
        ->and(DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->value('needs_rescheduling'))->toBeFalse()
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0);

    ac_at($this->clock, 0, 21, 30); // after the booking ends, still inside the grace
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBe('IN');

    ac_at($this->clock, 0, 22, 5); // past 21:00 + 60: the check-out runs, and the ended booking is not flagged
    $this->artisan('presence:auto-checkout')->assertSuccessful();
    expect(ac_status($this->doctorId))->toBeNull()
        ->and(DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->value('needs_rescheduling'))->toBeFalse()
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0);
});

/** A walk-in queue event at a Manila wall time on the pinned clock's day offset (the append-only log's occurred_at is set explicitly). */
function ac_queue_event(string $queueId, string $entryId, PinnedClock $clock, int $dayOffset, int $hour, int $minute): void
{
    $at = $clock->at->setTimezone('Asia/Manila')->startOfDay()->addDays($dayOffset)->setTime($hour, $minute)->utc();
    DB::table('walkin_queue_events')->insert([
        'walkin_queue_id' => $queueId, 'walkin_queue_entry_id' => $entryId, 'event_type' => 'ARRIVED', 'from_status' => 'REGISTERED', 'to_status' => 'WAITING',
        'actor_id' => null, 'reason' => null, 'metadata' => null, 'occurred_at' => $at,
    ]);
}

it('waits for an idle period before closing an earlier-day session (HAG-46): activity at 23:50 keeps it IN at 00:05', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    ac_queue_event(wi_queue_id(), $entry, $this->clock, 0, 23, 50);
    Log::spy();

    ac_at($this->clock, 1, 0, 5); // 15 minutes after the last activity; the grace is 60
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBe('IN')->and(wi_status($entry))->toBe('WAITING');
    Log::shouldHaveReceived('info')->with('PRESENCE_AUTO_CHECKOUT_SKIPPED', Mockery::on(fn (array $ctx) => $ctx['reason_code'] === 'recent_activity'
        && array_keys($ctx) === ['doctor_presence_session_id', 'doctor_profile_id', 'clinic_location_id', 'reason_code']))->once();

    // Idle past the grace (00:50): the session closes, the queue is paused and the entry is held, not cancelled.
    ac_at($this->clock, 1, 0, 55);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull()
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('checkout_reason'))->toBe('CLINIC_CLOSED')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('PAUSED')
        ->and(wi_status($entry))->toBe('WAITING');

    // The past-day sweep (hourly at :15) now closes the queue and cancels the held entry with END_OF_DAY.
    ac_at($this->clock, 1, 1, 15);
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('CLOSED')
        ->and(wi_status($entry))->toBe('CANCELLED')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->value('reason'))->toBe('END_OF_DAY');
});

it('does not gate a session checked in on the same day: past closing still closes it, whatever the queue did', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    ac_queue_event(wi_queue_id(), $entry, $this->clock, 0, 18, 25);

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull();
});

it('sends the patient a hold notice on a same-day SYSTEM check-out (5.8-3 S2 keeps it)', function () {
    walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);

    ac_at($this->clock, 0, 18, 30);
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $this->user->id)->where('type', 'walkins.doctor_checked_out')->count())->toBe(1);
});

it('sends no hold notice on an earlier-day SYSTEM check-out: the sweep cancels later and tells the patient once (5.8-3 S2)', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);

    ac_at($this->clock, 1, 0, 5); // Tuesday 00:05, no queue activity: the check-out fires now
    $this->artisan('presence:auto-checkout')->assertSuccessful();

    expect(ac_status($this->doctorId))->toBeNull()
        ->and(wi_status($entry))->toBe('WAITING')
        ->and(DB::table('notifications')->where('notifiable_id', $this->user->id)->where('type', 'walkins.doctor_checked_out')->count())->toBe(0);

    ac_at($this->clock, 1, 1, 15);
    $this->artisan('walkins:close-past-days')->assertSuccessful();

    expect(wi_status($entry))->toBe('CANCELLED')
        ->and(DB::table('notifications')->where('notifiable_id', $this->user->id)->where('type', 'walkins.doctor_checked_out')->count())->toBe(0);
});
