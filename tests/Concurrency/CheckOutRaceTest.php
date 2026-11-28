<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\Presence\Exceptions\ActiveConsultationBlocksChange;
use App\Modules\Presence\Exceptions\InvalidPresenceTransition;
use App\Modules\Presence\Jobs\AutoCheckOut;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Presence\Services\PresenceSessions;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\DTOs\CloseDisposition;
use App\Modules\WalkIns\DTOs\RegistrationCommand;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Modules\WalkIns\Enums\CloseAction;
use App\Modules\WalkIns\Enums\PayerType;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Exceptions\DoctorNotAvailable;
use App\Modules\WalkIns\Exceptions\RegistrationRefused;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\QueueClose;
use App\Modules\WalkIns\Services\QueueOperations;
use App\Modules\WalkIns\Services\QueueTransfer;
use App\Modules\WalkIns\Services\WalkInRegistration;
use App\Shared\Contracts\Clock;
use App\Shared\Exceptions\DomainException;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Increment 5.8-T: check-out races (brief §3 rows S3a to S3h), run as real forked processes on committed data, as CallNextRaceTest does.
 * Every racer reports its outcome as data: a refusal carries its class, its error code and its reason code (HAG-30 N-2), so the assertions
 * check the code and the reason, not only that an exception was thrown.
 *
 * "Ordered" means the second racer waits on committed data until the first racer's effect is visible (cor_after), so the order is fixed.
 * Patient entries are used wherever a grant or a consultation is asserted; a guest is only registered, never granted.
 *
 * Check-out holds the doctor's presence session row FOR UPDATE first (PresenceCheckOut), so a start, a call or a registration that reads the
 * presence row cannot interleave with the check-out's writes. These tests fix the observable result of each order.
 */

beforeEach(function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    fresh_test_database();
    $this->seed(RbacSeeder::class);
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    doctor_in($this->w);
    [$this->staffUser, $this->staffHeaders] = clinic_member($this->w['clinic'], Role::ClinicStaff);
    $this->doctorUser = User::query()->findOrFail($this->w['doctor']->user_id);
    [$this->userA, $this->profileA, $this->headersA] = remote_patient();
    [$this->userB, $this->profileB, $this->headersB] = remote_patient();
    app('auth')->forgetGuards();
});

afterEach(function () {
    DB::purge('pgsql_second');
    fresh_test_database();
});

/** A remote patient registered and arrived (WAITING) through the API; returns the entry id. Runs in the parent. */
function cor_patient_entry(array $headers, string $profile): string
{
    app('auth')->forgetGuards();
    $entry = register_walkin($headers, patient_body(test()->w['service'], $profile))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    wi_post("{$entry}/arrive", test()->staffHeaders)->assertOk();

    return $entry;
}

/** A patient registered, arrived and called through the API; returns [entry id, consultation id]. Runs in the parent. */
function cor_called_patient(array $headers, string $profile): array
{
    $entry = cor_patient_entry($headers, $profile);
    app('auth')->forgetGuards();
    wi_call_next(wi_queue_id(), test()->staffHeaders)->assertOk()->assertJsonPath('data.id', $entry);

    return [$entry, (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id')];
}

/** A patient called and started (IN_PROGRESS) through the lifecycle; returns [entry id, consultation id]. */
function cor_running_patient(array $headers, string $profile): array
{
    [$entry, $consultation] = cor_called_patient($headers, $profile);
    app(ConsultationLifecycle::class)->start(test()->doctorUser, Consultation::query()->findOrFail($consultation));

    return [$entry, $consultation];
}

/** A patient started then interrupted (INTERRUPTED); returns [entry id, consultation id]. */
function cor_interrupted_patient(array $headers, string $profile): array
{
    [$entry, $consultation] = cor_running_patient($headers, $profile);
    app(ConsultationLifecycle::class)->interrupt(Consultation::query()->findOrFail($consultation));

    return [$entry, $consultation];
}

/**
 * Runs one racer and reports the outcome as data: done (with the value), or the refusal's class, error code and reason code.
 * A crash is not caught here; the fork reports it as 'error' and the assertions fail on it.
 */
function cor_attempt(Closure $work): array
{
    try {
        return ['done' => $work()];
    } catch (Throwable $e) {
        return [
            'refused' => $e::class,
            'code' => $e instanceof DomainException ? $e->errorCode()->value : null,
            'reason' => method_exists($e, 'reasonCode') ? $e->reasonCode() : null,
            // Kept for the failure output: a non-domain throwable (QueryException, LogicException, ...) must show its class and message.
            'message' => $e->getMessage(),
            'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        ];
    }
}

/** Holds a racer until a condition is true on committed data, so two racers run in a fixed order. Gives up after 15 s. */
function cor_after(Closure $condition, Closure $work): Closure
{
    return function () use ($condition, $work) {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The ordering gate timed out.');
            }
            usleep(20_000);
        }

        return $work();
    };
}

/** The doctor's check-out (or the staff member's, on behalf). The disposition is HOLD, so nothing is cancelled. */
function cor_checkout(string $by = 'doctor'): Closure
{
    return fn () => cor_attempt(function () use ($by) {
        $actor = $by === 'staff'
            ? presence_actor(test()->staffUser, 'STAFF_CONSOLE')
            : presence_actor(test()->doctorUser, 'DOCTOR_APP');
        app(PresenceCheckOut::class)->checkOut($actor, (string) test()->w['doctor']->id, test()->w['location'], CheckOutReason::EndOfShift, CheckOutDisposition::HoldForReassignment, false);

        return 'OUT';
    });
}

function cor_start(string $consultation): Closure
{
    return fn () => cor_attempt(fn () => app(ConsultationLifecycle::class)->start(test()->doctorUser, Consultation::query()->findOrFail($consultation))->status->value);
}

function cor_end(string $consultation): Closure
{
    return fn () => cor_attempt(fn () => app(ConsultationLifecycle::class)->end(test()->doctorUser, Consultation::query()->findOrFail($consultation))->status->value);
}

function cor_resume(string $consultation): Closure
{
    return fn () => cor_attempt(fn () => app(ConsultationLifecycle::class)->resume(Consultation::query()->findOrFail($consultation))->status->value);
}

function cor_call(string $queue): Closure
{
    return fn () => cor_attempt(fn () => app(QueueOperations::class)->callNext(test()->staffUser, WalkInQueue::query()->findOrFail($queue))->id);
}

/** A guest registration by staff (the guest is never granted, so only the queue and presence effects are asserted). */
function cor_register(): Closure
{
    return fn () => cor_attempt(function () {
        $command = new RegistrationCommand(
            test()->staffUser, RegistrationChannel::StaffConsole, DoctorService::findOrFail(test()->w['service']), (string) Str::uuid7(),
            PayerType::SelfPay, null, 'Delta Guest', null, null, PriorityLevel::Normal, null, null, 'v1', false,
        );

        return app(WalkInRegistration::class)->register($command)->entry->id;
    });
}

function cor_session_status(): ?string
{
    return DB::table('doctor_presence_sessions')->where('doctor_profile_id', test()->w['doctor']->id)->whereNull('checked_out_at')->value('current_status');
}

function cor_out_sessions(): int
{
    return DB::table('doctor_presence_sessions')->where('doctor_profile_id', test()->w['doctor']->id)->whereNotNull('checked_out_at')->count();
}

function cor_out_events(): int
{
    return DB::table('doctor_presence_events')->where('doctor_profile_id', test()->w['doctor']->id)->where('new_status', 'OUT')->count();
}

function cor_called_count(): int
{
    return DB::table('walkin_queue_entries')->where('status', 'CALLED')->count();
}

function cor_entries(): int
{
    return DB::table('walkin_queue_entries')->count();
}

function cor_consultation_status(string $consultation): string
{
    return (string) DB::table('consultations')->where('id', $consultation)->value('status');
}

function cor_entry_status(string $entry): string
{
    return (string) DB::table('walkin_queue_entries')->where('id', $entry)->value('status');
}

function cor_grants(string $entry): int
{
    return DB::table('care_relationships')->where('source_id', $entry)->count();
}

function cor_doctor_in_progress(): int
{
    return DB::table('consultations')->where('doctor_profile_id', test()->w['doctor']->id)->where('status', 'IN_PROGRESS')->count();
}

// S3a: start vs check-out, start first.

it('S3a start vs check-out, start first: the check-out is refused ACTIVE_CONSULTATION (in_progress); the session stays open and nothing changes', function () {
    [$entry, $consultation] = cor_called_patient($this->headersA, $this->profileA);
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));
    $running = fn () => cor_consultation_status($consultation) === 'IN_PROGRESS';

    $results = wi_race([cor_start($consultation), cor_after($running, cor_checkout('doctor'))]);

    expect($results[0]['ok']['done'] ?? null)->toBe('IN_PROGRESS')
        ->and($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[1]['ok']['reason'] ?? null)->toBe('in_progress')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_sessions())->toBe(0)->and(cor_out_events())->toBe(0)
        ->and(cor_entry_status($entry))->toBe('IN_CONSULTATION')->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS')
        ->and(cor_grants($entry))->toBe(1)
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->w['doctor']->id)->value('status'))->toBe('OPEN')
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0)
        ->and((bool) DB::table('appointments')->where('doctor_profile_id', $this->w['doctor']->id)->value('needs_rescheduling'))->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'presence.checked_out')->count())->toBe(0);
});

// S3b: start vs check-out, check-out first.

it('S3b start vs check-out, check-out first: a CALLED patient blocks the check-out (called), so the check-out cannot run first and the start then succeeds (ruling 1: the check-out cannot run first, as a CALLED patient refuses it)', function () {
    [$entry, $consultation] = cor_called_patient($this->headersA, $this->profileA);

    // A refused check-out changes nothing to wait on, so it runs first in the parent; the start then runs on the same committed rows.
    $results = wi_race([cor_checkout('doctor')]);

    expect($results[0]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[0]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[0]['ok']['reason'] ?? null)->toBe('called')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0);

    $started = app(ConsultationLifecycle::class)->start(test()->doctorUser, Consultation::query()->findOrFail($consultation));
    expect($started->status->value)->toBe('IN_PROGRESS')->and(cor_grants($entry))->toBe(1);
});

// S3c: the same two orders, with staff checking the doctor out on behalf.

it('S3c staff check-out on behalf vs the doctor\'s start, start first: the check-out is refused in_progress (STAFF_CONSOLE attempt changes nothing)', function () {
    [$entry, $consultation] = cor_called_patient($this->headersA, $this->profileA);
    $running = fn () => cor_consultation_status($consultation) === 'IN_PROGRESS';

    $results = wi_race([cor_start($consultation), cor_after($running, cor_checkout('staff'))]);

    expect($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[1]['ok']['reason'] ?? null)->toBe('in_progress')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)->and(cor_grants($entry))->toBe(1);
});

it('S3c staff check-out on behalf vs the doctor\'s start, check-out first: the staff check-out is refused called (CALLED patient), the start then succeeds', function () {
    [$entry, $consultation] = cor_called_patient($this->headersA, $this->profileA);

    $results = wi_race([cor_checkout('staff')]);

    expect($results[0]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[0]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')->and($results[0]['ok']['reason'] ?? null)->toBe('called')
        ->and(cor_out_events())->toBe(0);
    expect(app(ConsultationLifecycle::class)->start(test()->doctorUser, Consultation::query()->findOrFail($consultation))->status->value)->toBe('IN_PROGRESS');
    expect(cor_grants($entry))->toBe(1);
});

// S3d: resume (of an INTERRUPTED consultation) vs check-out.

it('S3d resume vs check-out, resume first: the check-out is refused ACTIVE_CONSULTATION (in_progress) and nothing changes', function () {
    [$entry, $consultation] = cor_interrupted_patient($this->headersA, $this->profileA);
    $resumed = fn () => cor_consultation_status($consultation) === 'IN_PROGRESS';

    $results = wi_race([cor_resume($consultation), cor_after($resumed, cor_checkout('doctor'))]);

    expect($results[0]['ok']['done'] ?? null)->toBe('IN_PROGRESS')
        ->and($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[1]['ok']['reason'] ?? null)->toBe('in_progress')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)
        ->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS')->and(cor_entry_status($entry))->toBe('IN_CONSULTATION');
});

it('S3d check-out before the resume is impossible: an interrupted consultation refuses the check-out (interrupted), and the resume still succeeds after', function () {
    [$entry, $consultation] = cor_interrupted_patient($this->headersA, $this->profileA);

    $results = wi_race([cor_checkout('doctor')]);

    expect($results[0]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[0]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[0]['ok']['reason'] ?? null)->toBe('interrupted')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)
        ->and(cor_consultation_status($consultation))->toBe('INTERRUPTED');

    app(ConsultationLifecycle::class)->resume(Consultation::query()->findOrFail($consultation));
    expect(cor_consultation_status($consultation))->toBe('IN_PROGRESS');
});

// S3e: call-next vs check-out.

it('S3e call-next vs check-out, call first: the check-out is refused ACTIVE_CONSULTATION (called) and no CALLED row is left unexplained', function () {
    $entry = cor_patient_entry($this->headersA, $this->profileA);
    $queue = wi_queue_id();
    $called = fn () => cor_called_count() === 1;

    $results = wi_race([cor_call($queue), cor_after($called, cor_checkout('doctor'))]);

    expect($results[0]['ok']['done'] ?? null)->toBe($entry)
        ->and($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[1]['ok']['reason'] ?? null)->toBe('called')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)
        ->and(cor_called_count())->toBe(1)->and(cor_entry_status($entry))->toBe('CALLED')
        ->and(DB::table('consultations')->where('walkin_queue_entry_id', $entry)->value('status'))->toBe('SCHEDULED');
});

it('S3e call-next vs check-out, check-out first: the call is refused DOCTOR_NOT_AVAILABLE (checked_out) and no CALLED row is written', function () {
    $entry = cor_patient_entry($this->headersA, $this->profileA);
    $queue = wi_queue_id();
    $checkedOut = fn () => cor_out_sessions() === 1;

    $results = wi_race([cor_after($checkedOut, cor_call($queue)), cor_checkout('doctor')]);

    expect($results[1]['ok']['done'] ?? null)->toBe('OUT')
        ->and($results[0]['ok']['refused'] ?? null)->toBe(DoctorNotAvailable::class)
        ->and($results[0]['ok']['code'] ?? null)->toBe('DOCTOR_NOT_AVAILABLE')
        ->and($results[0]['ok']['reason'] ?? null)->toBe('checked_out')
        ->and(cor_called_count())->toBe(0)->and(DB::table('consultations')->count())->toBe(0)
        ->and(cor_entry_status($entry))->toBe('WAITING');
});

// S3f: staff registration vs check-out.

it('S3f registration vs check-out, registration first: the new entry is in the disposition (held, not cancelled) and the queue is paused', function () {
    $entries = fn () => cor_entries() >= 1;

    $results = wi_race([cor_register(), cor_after($entries, cor_checkout('doctor'))]);

    $newEntry = $results[0]['ok']['done'] ?? null;
    expect($newEntry)->toBeString()->and($results[1]['ok']['done'] ?? null)->toBe('OUT')
        ->and(cor_entry_status($newEntry))->toBe('WAITING')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->w['doctor']->id)->value('status'))->toBe('PAUSED')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $newEntry)->where('event_type', 'CANCELLED')->count())->toBe(0)
        ->and(cor_find((array) DB::table('audit_logs')->where('action', 'presence.checked_out')->first(), 'entries_held'))->toBe(1)
        ->and(cor_out_sessions())->toBe(1);
});

it('S3f registration vs check-out, check-out first: the registration is refused REGISTRATION_NOT_ALLOWED (doctor_checked_out), and no entry is written', function () {
    $checkedOut = fn () => cor_out_sessions() === 1;

    $results = wi_race([cor_after($checkedOut, cor_register()), cor_checkout('doctor')]);

    expect($results[1]['ok']['done'] ?? null)->toBe('OUT')
        ->and($results[0]['ok']['refused'] ?? null)->toBe(RegistrationRefused::class)
        ->and($results[0]['ok']['code'] ?? null)->toBe('REGISTRATION_NOT_ALLOWED')
        ->and($results[0]['ok']['reason'] ?? null)->toBe('doctor_checked_out')
        ->and(cor_entries())->toBe(0)->and(cor_out_sessions())->toBe(1);
});

// S3g: the doctor's check-out and the staff check-out on behalf, at the same time.

it('S3g doctor check-out vs staff check-out on behalf, simultaneous: exactly one OUT row and one event; the loser is refused not_checked_in', function () {
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));

    $results = wi_race([cor_checkout('doctor'), cor_checkout('staff')]);

    $winners = array_filter($results, fn (array $r) => ($r['ok']['done'] ?? null) === 'OUT');
    $losers = array_filter($results, fn (array $r) => isset($r['ok']['refused']));
    expect(count($winners))->toBe(1)->and(count($losers))->toBe(1);
    $winnerMethod = array_key_first($winners) === 0 ? 'DOCTOR_APP' : 'STAFF_CONSOLE';

    $loser = array_values($losers)[0]['ok'];
    expect($loser['refused'])->toBe(InvalidPresenceTransition::class)
        ->and($loser['code'])->toBe('INVALID_STATE_TRANSITION')->and($loser['reason'])->toBe('not_checked_in')
        ->and(cor_out_sessions())->toBe(1)->and(cor_out_events())->toBe(1)
        ->and(DB::table('doctor_presence_events')->where('new_status', 'OUT')->value('change_method'))->toBe($winnerMethod)
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->value('checked_out_method'))->toBe($winnerMethod)
        ->and(DB::table('rescheduling_tasks')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'presence.checked_out')->count())->toBe(1);
});

// S3h: check-out vs end, from IN_PROGRESS.

it('S3h end vs check-out, end first: the end commits and the check-out then succeeds', function () {
    [$entry, $consultation] = cor_running_patient($this->headersA, $this->profileA);
    $ended = fn () => cor_consultation_status($consultation) === 'COMPLETED';

    $results = wi_race([cor_end($consultation), cor_after($ended, cor_checkout('doctor'))]);

    expect($results[0]['ok']['done'] ?? null)->toBe('COMPLETED')
        ->and($results[1]['ok']['done'] ?? null)->toBe('OUT')
        ->and(cor_session_status())->toBeNull()->and(cor_out_sessions())->toBe(1)
        ->and(cor_consultation_status($consultation))->toBe('COMPLETED');
});

it('S3h check-out before the end: the check-out is refused ACTIVE_CONSULTATION (in_progress); the end then succeeds, as end never refuses on presence', function () {
    [$entry, $consultation] = cor_running_patient($this->headersA, $this->profileA);

    // Check-out first: a refused check-out is not a race, so it runs alone; the end then runs on the same committed rows.
    $results = wi_race([cor_checkout('doctor')]);

    expect($results[0]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[0]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($results[0]['ok']['reason'] ?? null)->toBe('in_progress')
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)
        ->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS');

    $ended = app(ConsultationLifecycle::class)->end(test()->doctorUser, Consultation::query()->findOrFail($consultation));
    expect($ended->status->value)->toBe('COMPLETED')->and(cor_session_status())->toBe('IN');
});

// S3a lock row (HAG-30 M2): the check-out's session lock (PresenceCheckOut, lockForUpdate on the session row) is what makes this deterministic.
// The start is held open on pgsql_second: the entry is IN_CONSULTATION and the consultation IN_PROGRESS, but not yet committed. The child
// check-out is forked while the lock is held. With the lock, it waits on the session row, then reads IN_PROGRESS after the commit and is
// refused in_progress. Without the lock, the check-out reads the pre-commit state (the entry is still CALLED), so it is refused `called`
// instead. The `in_progress` reason assertion below therefore fails if the lock is dropped, on every run, without relying on timing. The
// `in_progress` pin is the only guard that proves the wait; no other assertion here distinguishes the two orders. The elapsed > 0.4 s check
// proves the child really waited on the held lock.
it('S3a lock order: a check-out that waits on a held session row is refused in_progress after the start commits, and is never OUT', function () {
    [$entry, $consultation] = cor_called_patient($this->headersA, $this->profileA);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();
    $second->table('walkin_queue_entries')->where('id', $entry)->update(['status' => 'IN_CONSULTATION', 'consultation_started_at' => now()]);
    $second->table('consultations')->where('id', $consultation)->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);

    $children = wi_race_with_parent([cor_checkout('doctor')], function () use ($second) {
        usleep(1_000_000);
        $second->commit();
    });

    expect($children[0]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($children[0]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and($children[0]['ok']['reason'] ?? null)->toBe('in_progress')
        ->and($children[0]['elapsed'])->toBeGreaterThan(0.4)
        ->and(cor_session_status())->toBe('IN')->and(cor_out_sessions())->toBe(0)->and(cor_out_events())->toBe(0)
        ->and(cor_entry_status($entry))->toBe('IN_CONSULTATION')->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS');
});

/*
 * One-barrier invariant rows (HAG-30 M2). Both racers start together, so the order is not fixed: each row asserts what must hold under
 * either interleaving. Both racers take the same doctor session row, so the contention is real, but a single run cannot prove the lock
 * order. The ordered rows above fix the order of each pair; they serialise through cor_after, so they do not reach the check-out's session
 * lock. That lock is proven by the "S3a lock order" row above.
 */

/** Crosses a start and a check-out of one called patient at one barrier: the start always commits; the check-out is never OUT. */
function cor_start_vs_checkout_invariant(string $by): void
{
    [$entry, $consultation] = cor_called_patient(test()->headersA, test()->profileA);

    $results = wi_race([cor_start($consultation), cor_checkout($by)]);

    expect($results[0]['ok']['done'] ?? null)->toBe('IN_PROGRESS')
        ->and($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and(in_array($results[1]['ok']['reason'] ?? null, ['called', 'in_progress'], true))->toBeTrue()
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)
        ->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS')->and(cor_grants($entry))->toBe(1);
}

it('S3a one barrier: start vs check-out, simultaneous: the start always commits and the check-out is refused ACTIVE_CONSULTATION, never OUT (invariant)', function () {
    cor_start_vs_checkout_invariant('doctor');
});

it('S3c one barrier: start vs staff check-out on behalf, simultaneous: the same invariant (invariant)', function () {
    cor_start_vs_checkout_invariant('staff');
});

it('S3d one barrier: resume vs check-out, simultaneous: the resume always commits and the check-out is refused ACTIVE_CONSULTATION, never OUT (invariant)', function () {
    [$entry, $consultation] = cor_interrupted_patient($this->headersA, $this->profileA);

    $results = wi_race([cor_resume($consultation), cor_checkout('doctor')]);

    expect($results[0]['ok']['done'] ?? null)->toBe('IN_PROGRESS')
        ->and($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
        ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')
        ->and(in_array($results[1]['ok']['reason'] ?? null, ['interrupted', 'in_progress'], true))->toBeTrue()
        ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0)
        ->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS')->and(cor_entry_status($entry))->toBe('IN_CONSULTATION');
});

it('S3e one barrier: call-next vs check-out, simultaneous: the call and the check-out are never both applied (invariant)', function () {
    $entry = cor_patient_entry($this->headersA, $this->profileA);
    $queue = wi_queue_id();

    $results = wi_race([cor_call($queue), cor_checkout('doctor')]);

    if (($results[0]['ok']['done'] ?? null) === $entry) {
        expect($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
            ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')->and($results[1]['ok']['reason'] ?? null)->toBe('called')
            ->and(cor_out_events())->toBe(0)->and(cor_called_count())->toBe(1)->and(cor_entry_status($entry))->toBe('CALLED');
    } else {
        expect($results[1]['ok']['done'] ?? null)->toBe('OUT')
            ->and($results[0]['ok']['refused'] ?? null)->toBe(DoctorNotAvailable::class)
            ->and($results[0]['ok']['code'] ?? null)->toBe('DOCTOR_NOT_AVAILABLE')->and($results[0]['ok']['reason'] ?? null)->toBe('checked_out')
            ->and(cor_called_count())->toBe(0)->and(cor_entry_status($entry))->toBe('WAITING')->and(cor_out_sessions())->toBe(1);
    }
});

it('S3f one barrier: registration vs check-out, simultaneous: the entry is held in the disposition, or the registration is refused doctor_checked_out (invariant)', function () {
    $results = wi_race([cor_register(), cor_checkout('doctor')]);

    expect($results[1]['ok']['done'] ?? null)->toBe('OUT');
    $newEntry = $results[0]['ok']['done'] ?? null;
    if ($newEntry !== null) {
        expect(cor_entry_status($newEntry))->toBe('WAITING')
            ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->w['doctor']->id)->value('status'))->toBe('PAUSED')
            ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $newEntry)->where('event_type', 'CANCELLED')->count())->toBe(0)
            ->and(cor_find((array) DB::table('audit_logs')->where('action', 'presence.checked_out')->first(), 'entries_held'))->toBe(1);
    } else {
        expect($results[0]['ok']['refused'] ?? null)->toBe(RegistrationRefused::class)
            ->and($results[0]['ok']['code'] ?? null)->toBe('REGISTRATION_NOT_ALLOWED')->and($results[0]['ok']['reason'] ?? null)->toBe('doctor_checked_out')
            ->and(cor_entries())->toBe(0);
    }
});

it('S3h one barrier: end vs check-out, simultaneous: the end always commits; the check-out is OUT only after it, otherwise refused in_progress (invariant)', function () {
    [, $consultation] = cor_running_patient($this->headersA, $this->profileA);

    $results = wi_race([cor_end($consultation), cor_checkout('doctor')]);

    expect($results[0]['ok']['done'] ?? null)->toBe('COMPLETED')->and(cor_consultation_status($consultation))->toBe('COMPLETED');
    if (($results[1]['ok']['done'] ?? null) === 'OUT') {
        expect(cor_out_sessions())->toBe(1);
    } else {
        expect($results[1]['ok']['refused'] ?? null)->toBe(ActiveConsultationBlocksChange::class)
            ->and($results[1]['ok']['code'] ?? null)->toBe('ACTIVE_CONSULTATION')->and($results[1]['ok']['reason'] ?? null)->toBe('in_progress')
            ->and(cor_session_status())->toBe('IN')->and(cor_out_events())->toBe(0);
    }
});

/*
 * Increment 5.8-T2: transfer, close and call-next races against the check-out (brief §3 rows T1 to T3 and C1). Each row is a one-barrier
 * invariant: the two racers start together, so the order is not fixed, and each assertion holds under either interleaving.
 */

/** A second doctor at the same location, with her queue for today (a guest registered for her). Returns the doctor, service and queue. */
function cor_second(): array
{
    $second = wi_second_doctor(test()->w);
    app('auth')->forgetGuards();
    register_walkin(test()->staffHeaders, guest_body($second['service'], 'Target Guest'))->assertCreated();
    $queue = (string) DB::table('walkin_queues')->where('doctor_profile_id', $second['doctor']->id)->value('id');

    return [...$second, 'queue' => $queue];
}

/** A transfer of one entry to a queue, by staff. The result is the queue the entry now sits in. */
function cor_transfer(string $entry, string $target): Closure
{
    return fn () => cor_attempt(fn () => app(QueueTransfer::class)->transfer(test()->staffUser, WalkInQueueEntry::query()->findOrFail($entry), $target, false)->walkin_queue_id);
}

/** A close of the staff's queue, with TOLD_IN_PERSON for the one entry (no notice is sent, so only the queue effect is asserted). */
function cor_close(string $entry): Closure
{
    return fn () => cor_attempt(fn () => app(QueueClose::class)->close(test()->staffUser, WalkInQueue::query()->findOrFail(wi_queue_id()), [new CloseDisposition($entry, CloseAction::ToldInPerson)])->status);
}

/** The doctor checks in again at the location. */
function cor_check_in(): Closure
{
    return fn () => cor_attempt(function () {
        app(PresenceSessions::class)->checkIn(presence_actor(test()->doctorUser, 'DOCTOR_APP'), (string) test()->w['doctor']->id, test()->w['location'], null);

        return 'IN';
    });
}

/** Whether a racer's refusal is one of the 409 codes a lost race may return. */
function cor_is_lost_race(array $result): bool
{
    return in_array($result['ok']['code'] ?? null, ['CONCURRENT_MODIFICATION', 'INVALID_STATE_TRANSITION'], true);
}

// T1: transfer A to B vs check-out of A. HOLD cancels nothing, so the transfer always succeeds, from either order.

it('T1 transfer A to B vs check-out of A (one barrier): the entry ends in B, is never cancelled, and the check-out is OUT', function () {
    $entry = cor_patient_entry($this->headersA, $this->profileA);
    $target = cor_second()['queue'];

    $results = wi_race([cor_transfer($entry, $target), cor_checkout('doctor')]);

    expect($results[1]['ok']['done'] ?? null)->toBe('OUT')
        ->and($results[0]['ok']['done'] ?? null)->toBe($target)
        ->and(DB::table('walkin_queue_entries')->where('id', $entry)->value('walkin_queue_id'))->toBe($target)
        ->and(cor_entry_status($entry))->toBe('WAITING')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'CANCELLED')->count())->toBe(0)
        ->and(cor_out_sessions())->toBe(1);
});

// T2: transfer vs call-next on the source queue. Both take the queue row, so one wins and the other sees its result.

it('T2 transfer of the first entry vs call-next on the source queue (one barrier): exactly one wins, and no entry is both called and moved', function () {
    $first = cor_patient_entry($this->headersA, $this->profileA);
    $second = cor_patient_entry($this->headersB, $this->profileB);
    $source = wi_queue_id();
    $target = cor_second()['queue'];

    $results = wi_race([cor_transfer($first, $target), cor_call($source)]);

    if (($results[0]['ok']['done'] ?? null) === $target) {
        // The transfer won: the call then takes the next waiting entry, the second patient.
        expect(DB::table('walkin_queue_entries')->where('id', $first)->value('walkin_queue_id'))->toBe($target)
            ->and(cor_entry_status($first))->toBe('WAITING')
            ->and($results[1]['ok']['done'] ?? null)->toBe($second);
    } else {
        // The call won: the first entry is CALLED, so the transfer is refused (INVALID_STATE_TRANSITION) and it stays in the source queue.
        expect($results[1]['ok']['done'] ?? null)->toBe($first)
            ->and($results[0]['ok']['code'] ?? null)->toBe('INVALID_STATE_TRANSITION')
            ->and(DB::table('walkin_queue_entries')->where('id', $first)->value('walkin_queue_id'))->toBe($source)
            ->and(cor_entry_status($first))->toBe('CALLED');
    }
});

// T3: two transfers in opposite directions. The queue rows are taken in ascending id order, so there is no deadlock.

it('T3 two transfers in opposite directions (one barrier): no deadlock, each entry either moves or is refused with a lost-race code', function () {
    $second = cor_second();
    $a = cor_patient_entry($this->headersA, $this->profileA);
    $source = (string) DB::table('walkin_queues')->where('doctor_profile_id', $this->w['doctor']->id)->value('id'); // the first doctor's queue (the first patient registered there)
    $b = register_walkin(test()->staffHeaders, guest_body($second['service'], 'Opposite Guest'))->assertCreated()->json('data.id');

    $results = wi_race([cor_transfer($a, $second['queue']), cor_transfer($b, $source)]);

    foreach ($results as $result) {
        expect(isset($result['ok']['done']) || cor_is_lost_race($result))->toBeTrue();
    }
    expect(DB::table('walkin_queue_entries')->where('id', $a)->value('walkin_queue_id'))
        ->toBe(isset($results[0]['ok']['done']) ? $second['queue'] : $source)
        ->and(DB::table('walkin_queue_entries')->where('id', $b)->value('walkin_queue_id'))
        ->toBe(isset($results[1]['ok']['done']) ? $source : $second['queue'])
        ->and(cor_entry_status($a))->toBe('WAITING')->and(cor_entry_status($b))->toBe('WAITING');
});

// C1: close vs check-in of the same doctor. Close first: the queue is CLOSED and the check-in succeeds. Check-in first: close is refused.

it('C1 close vs check-in of the same doctor (one barrier): a close that commits leaves the queue CLOSED; a close that loses is refused doctor_checked_in', function () {
    $entry = cor_patient_entry($this->headersA, $this->profileA);
    (cor_checkout('doctor'))(); // the doctor checks out first (HOLD), so the queue is PAUSED and the close may run

    $results = wi_race([cor_close($entry), cor_check_in()]);

    expect($results[1]['ok']['done'] ?? null)->toBe('IN')->and(cor_session_status())->toBe('IN');
    if (isset($results[0]['ok']['done'])) {
        expect(DB::table('walkin_queues')->where('id', wi_queue_id())->value('status'))->toBe('CLOSED');
    } else {
        expect($results[0]['ok']['code'] ?? null)->toBe('INVALID_STATE_TRANSITION')->and($results[0]['ok']['reason'] ?? null)->toBe('doctor_checked_in')
            ->and(DB::table('walkin_queues')->where('id', wi_queue_id())->value('status'))->not->toBe('CLOSED');
    }
});

// A1: auto check-out (5.8-3, D-L) vs the start of a called patient. Either order, the block refuses the auto check-out, so it is skipped and
// logged once (PRESENCE_AUTO_CHECKOUT_SKIPPED), and the start always commits.

/** The scheduler's auto check-out of one open session. The result lists the skip records it logged and their reason codes. */
function cor_auto_checkout(string $session): Closure
{
    return fn () => cor_attempt(function () use ($session) {
        $reasons = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$reasons) {
            if ($event->message === 'PRESENCE_AUTO_CHECKOUT_SKIPPED') {
                $reasons[] = $event->context['reason_code'] ?? null;
            }
        });
        app()->call([new AutoCheckOut($session, (string) test()->w['doctor']->id, test()->w['location']), 'handle'], ['clock' => test()->clock]);

        return ['skips' => count($reasons), 'reasons' => $reasons];
    });
}

it('A1 auto check-out vs the start of a called patient (one barrier): the start always commits, the auto check-out is skipped and logged once, and nothing is closed', function () {
    [$entry, $consultation] = cor_called_patient($this->headersA, $this->profileA);
    $session = (string) DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->value('id');

    $results = wi_race([cor_start($consultation), cor_auto_checkout($session)]);

    expect($results[0]['ok']['done'] ?? null)->toBe('IN_PROGRESS')
        ->and($results[1]['ok']['done']['skips'] ?? null)->toBe(1)
        // The reason depends on which lock is taken first: if the start commits first the check-out sees IN_PROGRESS ('in_progress');
        // if the auto check-out takes the session lock first it sees the CALLED entry ('called'). Either way there is exactly one skip.
        ->and($results[1]['ok']['done']['reasons'] ?? null)->toHaveCount(1)
        ->and($results[1]['ok']['done']['reasons'][0] ?? null)->toBeIn(['in_progress', 'called'])
        ->and(cor_session_status())->toBe('IN')->and(cor_out_sessions())->toBe(0)->and(cor_out_events())->toBe(0)
        ->and(cor_consultation_status($consultation))->toBe('IN_PROGRESS')->and(cor_grants($entry))->toBe(1)
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0);
});

/** The value of a key anywhere in a row's columns (the audit metadata is JSON), or null. */
function cor_find(mixed $node, string $key): mixed
{
    if (is_string($node)) {
        $node = json_decode($node, true);
    }
    if (! is_array($node)) {
        return null;
    }
    if (array_key_exists($key, $node)) {
        return $node[$key];
    }
    foreach ($node as $child) {
        $found = cor_find($child, $key);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}
