<?php

use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Consultations\DTOs\UnfinishedConsultation;
use App\Modules\Consultations\Exceptions\ConsultationRefused;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Presence\Exceptions\ActiveConsultationBlocksChange;
use App\Modules\Presence\Services\PresenceSessions;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Exceptions\DoctorAlreadyCalling;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\QueueOperations;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Concurrency rows of increment 5.7 (brief §3), for the walk-in consultation start. Real forked processes, one database session each,
 * on committed data (the pattern of CallNextRaceTest). Each row runs in both orders. "Ordered" means the second racer waits on committed
 * data (cs_after) until the first racer's effect is visible, so the order is fixed; "simultaneous" releases both at once and asserts the
 * invariant that holds under either interleaving. Patient entries only: a guest is never granted, so a guest proves nothing here.
 *
 * Doctor-wide scenario 12 (two startable consultations of one doctor, raced) is built as: A interrupted (INTERRUPTED) and B CALLED, then
 * resume(A) races start(B). Only one of them may win the doctor's single IN_PROGRESS slot. A call during a consultation is allowed, so B
 * can be CALLED while A is interrupted. The 23505 backstop (uq_one_in_progress_consultation) is reached by binding an ActiveConsultations
 * stub that reports no running consultation, so only the unique index can refuse the start.
 *
 * The check-out is simulated, as the brief says: a racer closes the doctor's presence session to OUT in its own transaction (5.8 is not
 * built, so no service exists yet).
 */

beforeEach(function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    fresh_test_database();
    $this->seed(RbacSeeder::class);
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    doctor_in($this->w);
    [$this->staffUser, $this->staffHeaders] = clinic_member($this->w['clinic'], Role::ClinicStaff);
    $this->doctorUser = User::query()->findOrFail($this->w['doctor']->user_id);
    [$this->userA, $this->profileA, $this->headersA] = remote_patient();
    app('auth')->forgetGuards();
});

afterEach(function () {
    DB::purge('pgsql_second');
    fresh_test_database();
});

/** A remote patient registered, arrived and called through the API; returns [entry id, consultation id]. Runs in the parent. */
function cs_called(array $headers, string $profile): array
{
    app('auth')->forgetGuards();
    $entry = register_walkin($headers, patient_body(test()->w['service'], $profile))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    wi_post("{$entry}/arrive", test()->staffHeaders)->assertOk();
    app('auth')->forgetGuards();
    wi_call_next(wi_queue_id(), test()->staffHeaders)->assertOk()->assertJsonPath('data.id', $entry);

    return [$entry, (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id')];
}

/** Runs one racer. A refusal is a result the assertions read, not a crash; anything else is reported by the fork as 'error'. */
function cs_attempt(Closure $work): array
{
    try {
        return ['done' => $work()];
    } catch (Throwable $e) {
        return [
            'refused' => $e::class,
            'code' => method_exists($e, 'errorCode') ? $e->errorCode()->value : null,
            'reason' => method_exists($e, 'reasonCode') ? $e->reasonCode() : null,
        ];
    }
}

/** The refusal class of a racer, or its 'error' class if the fork crashed, or null when it finished. */
function cs_refusal(array $result): ?string
{
    return $result['error'] ?? ($result['ok']['refused'] ?? null);
}

/** The reason of a refusal, or null. */
function cs_reason(array $result): ?string
{
    return $result['ok']['reason'] ?? null;
}

/** The error code of a refusal (its ErrorCode value), or null. */
function cs_code(array $result): ?string
{
    return $result['ok']['code'] ?? null;
}

/**
 * Holds a racer until a condition is true on committed data (its own session), so two racers run in a fixed order. Gives up after 15 s.
 */
function cs_after(Closure $condition, Closure $work): Closure
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

function cs_start(string $consultation): Closure
{
    $doctor = test()->doctorUser;

    return fn () => cs_attempt(fn () => app(ConsultationLifecycle::class)->start($doctor, Consultation::query()->findOrFail($consultation))->status->value);
}

function cs_end(string $consultation): Closure
{
    $doctor = test()->doctorUser;

    return fn () => cs_attempt(fn () => app(ConsultationLifecycle::class)->end($doctor, Consultation::query()->findOrFail($consultation))->status->value);
}

function cs_break(): Closure
{
    $doctor = test()->doctorUser;
    $profile = test()->w['doctor']->id;
    $location = test()->w['location'];

    return fn () => cs_attempt(fn () => app(PresenceSessions::class)->startBreak(presence_actor($doctor), $profile, $location, 5, null)->current_status->value);
}

/** Simulated check-out (5.8 not built): closes the open presence session to OUT in one transaction. */
function cs_checkout(): Closure
{
    $profile = test()->w['doctor']->id;

    return fn () => cs_attempt(function () use ($profile) {
        $at = test()->clock->now();
        DB::transaction(function () use ($profile, $at) {
            DB::table('doctor_presence_sessions')->where('doctor_profile_id', $profile)->whereNull('checked_out_at')->lockForUpdate()->first();
            DB::table('doctor_presence_sessions')->where('doctor_profile_id', $profile)->whereNull('checked_out_at')->update([
                'current_status' => 'OUT', 'checked_out_at' => $at, 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM',
                'current_break_expected_end_at' => null, 'updated_at' => $at,
            ]);
        });

        return 'OUT';
    });
}

function cs_skip(string $entry): Closure
{
    $staff = test()->staffUser;

    return fn () => cs_attempt(function () use ($entry, $staff) {
        app(QueueOperations::class)->skip($staff, WalkInQueueEntry::query()->findOrFail($entry));

        return 'SKIPPED';
    });
}

function cs_no_show(string $entry): Closure
{
    $staff = test()->staffUser;

    return fn () => cs_attempt(function () use ($entry, $staff) {
        app(QueueOperations::class)->noShow($staff, WalkInQueueEntry::query()->findOrFail($entry));

        return 'NO_SHOW';
    });
}

function cs_status(string $consultation): string
{
    return (string) DB::table('consultations')->where('id', $consultation)->value('status');
}

function cs_doctor_status(): ?string
{
    return DB::table('doctor_presence_sessions')->where('doctor_profile_id', test()->w['doctor']->id)->whereNull('checked_out_at')->value('current_status');
}

function cs_grants(string $entry): int
{
    return DB::table('care_relationships')->where('source_id', $entry)->count();
}

it('double start of one consultation, in parallel: both answer done, one transition, one grant, one STARTED event, one audit row', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);

    $results = wi_race([cs_start($consultation), cs_start($consultation)]);

    expect(array_map(fn ($r) => $r['ok']['done'] ?? cs_refusal($r), $results))->toBe(['IN_PROGRESS', 'IN_PROGRESS'])
        ->and(cs_status($consultation))->toBe('IN_PROGRESS')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'STARTED')->count())->toBe(1)
        ->and(cs_grants($entry))->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'consultations.started')->count())->toBe(1);
});

it('start vs simulated check-out, check-out first: the start is refused checked_out, with no IN_PROGRESS row and no grant', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);

    $checkedOut = fn () => DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->where('current_status', 'OUT')->exists();

    $results = wi_race([cs_after($checkedOut, cs_start($consultation)), cs_checkout()]);

    expect(cs_refusal($results[0]))->toBe(ConsultationRefused::class)->and(cs_code($results[0]))->toBe('DOCTOR_NOT_AVAILABLE')->and(cs_reason($results[0]))->toBe('checked_out')
        ->and(cs_refusal($results[1]))->toBeNull()
        ->and(cs_status($consultation))->toBe('SCHEDULED')
        ->and(DB::table('consultations')->where('doctor_profile_id', $this->w['doctor']->id)->where('status', 'IN_PROGRESS')->count())->toBe(0)
        ->and(cs_grants($entry))->toBe(0)
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'STARTED')->count())->toBe(0);
});

it('start vs simulated check-out, start first: the start commits and the check-out then closes the session; the consultation keeps running (5.8 will refuse)', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    $running = fn () => DB::table('consultations')->where('id', $consultation)->where('status', 'IN_PROGRESS')->exists();

    $results = wi_race([cs_start($consultation), cs_after($running, cs_checkout())]);

    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('IN_PROGRESS')
        ->and(cs_refusal($results[1]))->toBeNull()
        ->and(cs_status($consultation))->toBe('IN_PROGRESS')
        ->and(cs_doctor_status())->toBeNull()
        ->and(cs_grants($entry))->toBe(1);
});

it('start vs simulated check-out, simultaneous: either the start commits, or it is refused checked_out with no write (invariant)', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);

    $results = wi_race([cs_start($consultation), cs_checkout()]);

    $started = cs_refusal($results[0]) === null;
    expect(cs_refusal($results[1]))->toBeNull()
        ->and(cs_status($consultation))->toBe($started ? 'IN_PROGRESS' : 'SCHEDULED')
        ->and(cs_grants($entry))->toBe($started ? 1 : 0)
        ->and(cs_doctor_status())->toBeNull();
    if (! $started) {
        expect(cs_refusal($results[0]))->toBe(ConsultationRefused::class)->and(cs_code($results[0]))->toBe('DOCTOR_NOT_AVAILABLE')
            ->and(cs_reason($results[0]))->toBe('checked_out');
    }
});

it('start vs break, break first: the start is refused on_break with no grant', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    $onBreak = fn () => cs_doctor_status() === 'ON_BREAK';

    $results = wi_race([cs_break(), cs_after($onBreak, cs_start($consultation))]);

    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('ON_BREAK')
        ->and(cs_refusal($results[1]))->toBe(ConsultationRefused::class)->and(cs_code($results[1]))->toBe('DOCTOR_NOT_AVAILABLE')->and(cs_reason($results[1]))->toBe('on_break')
        ->and(cs_status($consultation))->toBe('SCHEDULED')
        ->and(cs_grants($entry))->toBe(0);
});

it('start vs break, start first: the break is refused ACTIVE_CONSULTATION and the presence stays IN', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    $running = fn () => DB::table('consultations')->where('id', $consultation)->where('status', 'IN_PROGRESS')->exists();

    $results = wi_race([cs_start($consultation), cs_after($running, cs_break())]);

    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('IN_PROGRESS')
        ->and(cs_refusal($results[1]))->toBe(ActiveConsultationBlocksChange::class)
        ->and(cs_code($results[1]))->toBe('ACTIVE_CONSULTATION')->and(cs_reason($results[1]))->toBeNull()
        ->and(cs_doctor_status())->toBe('IN')
        ->and(cs_grants($entry))->toBe(1);
});

it('start vs skip, skip first: the skip commits, the start is refused and the consultation is CANCELLED, with no grant', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    $skipped = fn () => DB::table('walkin_queue_entries')->where('id', $entry)->where('status', 'SKIPPED')->exists();

    $results = wi_race([cs_skip($entry), cs_after($skipped, cs_start($consultation))]);

    expect(cs_refusal($results[0]))->toBeNull()
        ->and(cs_refusal($results[1]))->toBe(ConsultationRefused::class)->and(cs_code($results[1]))->toBe('INVALID_STATE_TRANSITION')->and(cs_reason($results[1]))->toBe('invalid_transition')
        ->and(cs_status($consultation))->toBe('CANCELLED')
        ->and(cs_grants($entry))->toBe(0)
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'STARTED')->count())->toBe(0);
});

it('start vs skip, start first: the skip is refused invalid_transition and the consultation is running', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    $running = fn () => DB::table('consultations')->where('id', $consultation)->where('status', 'IN_PROGRESS')->exists();

    $results = wi_race([cs_start($consultation), cs_after($running, cs_skip($entry))]);

    expect(cs_refusal($results[0]))->toBeNull()
        ->and(cs_refusal($results[1]))->toBe(InvalidEntryTransition::class)->and(cs_code($results[1]))->toBe('INVALID_STATE_TRANSITION')->and(cs_reason($results[1]))->toBe('invalid_transition')
        ->and(cs_status($consultation))->toBe('IN_PROGRESS')
        ->and(wi_status($entry))->toBe('IN_CONSULTATION')
        ->and(cs_grants($entry))->toBe(1);
});

it('start vs no-show at the recall limit, no-show first: the start is refused and the consultation is CANCELLED, with no grant', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    DB::table('walkin_queue_entries')->where('id', $entry)->update(['recall_count' => 99]);
    $noShow = fn () => DB::table('walkin_queue_entries')->where('id', $entry)->where('status', 'NO_SHOW')->exists();

    $results = wi_race([cs_no_show($entry), cs_after($noShow, cs_start($consultation))]);

    expect(cs_refusal($results[0]))->toBeNull()
        ->and(cs_refusal($results[1]))->toBe(ConsultationRefused::class)->and(cs_code($results[1]))->toBe('INVALID_STATE_TRANSITION')->and(cs_reason($results[1]))->toBe('invalid_transition')
        ->and(cs_status($consultation))->toBe('CANCELLED')
        ->and(cs_grants($entry))->toBe(0);
});

it('start vs no-show at the recall limit, start first: the no-show is refused and the consultation is running', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);
    DB::table('walkin_queue_entries')->where('id', $entry)->update(['recall_count' => 99]);
    $running = fn () => DB::table('consultations')->where('id', $consultation)->where('status', 'IN_PROGRESS')->exists();

    $results = wi_race([cs_start($consultation), cs_after($running, cs_no_show($entry))]);

    expect(cs_refusal($results[0]))->toBeNull()
        ->and(cs_refusal($results[1]))->toBe(InvalidEntryTransition::class)->and(cs_code($results[1]))->toBe('INVALID_STATE_TRANSITION')->and(cs_reason($results[1]))->toBe('invalid_transition')
        ->and(cs_status($consultation))->toBe('IN_PROGRESS')
        ->and(cs_grants($entry))->toBe(1);
});

it('end of a running consultation vs start of the next called patient, end first: the next start succeeds only after the end committed', function () {
    [$entryA, $consultationA] = cs_called($this->headersA, $this->profileA);
    app(ConsultationLifecycle::class)->start($this->doctorUser, Consultation::query()->findOrFail($consultationA));
    [$userB, $profileB, $headersB] = remote_patient();
    [$entryB, $consultationB] = cs_called($headersB, $profileB);
    expect(cs_status($consultationB))->toBe('SCHEDULED');
    $ended = fn () => cs_status($consultationA) === 'COMPLETED';

    $results = wi_race([cs_end($consultationA), cs_after($ended, cs_start($consultationB))]);

    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('COMPLETED')
        ->and(cs_refusal($results[1]))->toBeNull()->and($results[1]['ok']['done'])->toBe('IN_PROGRESS')
        ->and(cs_status($consultationB))->toBe('IN_PROGRESS')
        ->and(DB::table('consultations')->where('doctor_profile_id', $this->w['doctor']->id)->where('status', 'IN_PROGRESS')->count())->toBe(1);
});

it('end vs start of the next called patient, simultaneous: at most one IN_PROGRESS, and the next start is in progress only if the end committed (invariant)', function () {
    [$entryA, $consultationA] = cs_called($this->headersA, $this->profileA);
    app(ConsultationLifecycle::class)->start($this->doctorUser, Consultation::query()->findOrFail($consultationA));
    [, $profileB, $headersB] = remote_patient();
    [, $consultationB] = cs_called($headersB, $profileB);

    $results = wi_race([cs_end($consultationA), cs_start($consultationB)]);

    $startedB = cs_refusal($results[1]) === null;
    expect(cs_refusal($results[0]))->toBeNull()
        ->and(cs_status($consultationA))->toBe('COMPLETED')
        ->and(DB::table('consultations')->where('doctor_profile_id', $this->w['doctor']->id)->where('status', 'IN_PROGRESS')->count())->toBe($startedB ? 1 : 0);
    if (! $startedB) {
        expect(cs_refusal($results[1]))->toBe(ConsultationRefused::class)->and(cs_code($results[1]))->toBe('ACTIVE_CONSULTATION')->and(cs_reason($results[1]))->toBeNull();
    }
});

/** Consultation A started then interrupted (INTERRUPTED), and a second patient B called (CALLED) for the same doctor. Returns [entry A, consultation A, entry B, consultation B]. */
function cs_interrupted_and_next(): array
{
    [$entryA, $consultationA] = cs_called(test()->headersA, test()->profileA);
    app(ConsultationLifecycle::class)->start(test()->doctorUser, Consultation::query()->findOrFail($consultationA));
    app(ConsultationLifecycle::class)->interrupt(Consultation::query()->findOrFail($consultationA));
    [, $profileB, $headersB] = remote_patient();
    [$entryB, $consultationB] = cs_called($headersB, $profileB);

    return [$entryA, $consultationA, $entryB, $consultationB];
}

function cs_resume(string $consultation): Closure
{
    return fn () => cs_attempt(fn () => app(ConsultationLifecycle::class)->resume(Consultation::query()->findOrFail($consultation))->status->value);
}

function cs_in_progress_count(): int
{
    return DB::table('consultations')->where('doctor_profile_id', test()->w['doctor']->id)->where('status', 'IN_PROGRESS')->count();
}

it('resume A vs start B, simultaneous: exactly one of them wins the doctor\'s IN_PROGRESS slot; the loser is refused ACTIVE_CONSULTATION with no grant (invariant)', function () {
    [$entryA, $consultationA, $entryB, $consultationB] = cs_interrupted_and_next();

    $results = wi_race([cs_resume($consultationA), cs_start($consultationB)]);

    $resumed = cs_refusal($results[0]) === null;
    $started = cs_refusal($results[1]) === null;
    expect((int) $resumed + (int) $started)->toBe(1)
        ->and(cs_refusal($resumed ? $results[1] : $results[0]))->toBe(ConsultationRefused::class)
        ->and(cs_code($resumed ? $results[1] : $results[0]))->toBe('ACTIVE_CONSULTATION')->and(cs_reason($resumed ? $results[1] : $results[0]))->toBeNull()
        ->and(cs_in_progress_count())->toBe(1)
        ->and(cs_status($consultationA))->toBe($resumed ? 'IN_PROGRESS' : 'INTERRUPTED')
        ->and(cs_status($consultationB))->toBe($started ? 'IN_PROGRESS' : 'SCHEDULED')
        ->and(cs_grants($entryB))->toBe($started ? 1 : 0)
        ->and(cs_grants($entryA))->toBe(1);
});

it('resume A first, then start B: the start is refused ACTIVE_CONSULTATION, B stays SCHEDULED with no grant', function () {
    [, $consultationA, $entryB, $consultationB] = cs_interrupted_and_next();
    $resumedA = fn () => cs_status($consultationA) === 'IN_PROGRESS';

    $results = wi_race([cs_resume($consultationA), cs_after($resumedA, cs_start($consultationB))]);

    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('IN_PROGRESS')
        ->and(cs_refusal($results[1]))->toBe(ConsultationRefused::class)->and(cs_code($results[1]))->toBe('ACTIVE_CONSULTATION')->and(cs_reason($results[1]))->toBeNull()
        ->and(cs_status($consultationB))->toBe('SCHEDULED')
        ->and(cs_in_progress_count())->toBe(1)
        ->and(cs_grants($entryB))->toBe(0);
});

it('start B first, then resume A: the resume is refused ACTIVE_CONSULTATION, A stays INTERRUPTED and B keeps its grant', function () {
    [, $consultationA, $entryB, $consultationB] = cs_interrupted_and_next();
    $startedB = fn () => cs_status($consultationB) === 'IN_PROGRESS';

    $results = wi_race([cs_start($consultationB), cs_after($startedB, cs_resume($consultationA))]);

    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('IN_PROGRESS')
        ->and(cs_refusal($results[1]))->toBe(ConsultationRefused::class)->and(cs_code($results[1]))->toBe('ACTIVE_CONSULTATION')->and(cs_reason($results[1]))->toBeNull()
        ->and(cs_status($consultationA))->toBe('INTERRUPTED')
        ->and(cs_in_progress_count())->toBe(1)
        ->and(cs_grants($entryB))->toBe(1);
});

it('23505 backstop: with the presence check stubbed to "nothing running", a start of B while A is IN_PROGRESS is refused ACTIVE_CONSULTATION by the unique index, with no grant', function () {
    [$entryA, $consultationA, $entryB, $consultationB] = cs_interrupted_and_next();
    app(ConsultationLifecycle::class)->resume(Consultation::query()->findOrFail($consultationA));
    expect(cs_status($consultationA))->toBe('IN_PROGRESS');
    app()->instance(ActiveConsultations::class, new class implements ActiveConsultations
    {
        public function inProgressFor(string $doctorProfileId): ?string
        {
            return null;
        }

        public function unfinishedFor(string $doctorProfileId): ?UnfinishedConsultation
        {
            return null;
        }
    });
    app()->forgetInstance(ConsultationLifecycle::class);

    act_post("/api/v1/consultations/{$consultationB}/start", test()->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION');

    expect(cs_status($consultationB))->toBe('SCHEDULED')
        ->and(cs_status($consultationA))->toBe('IN_PROGRESS')
        ->and(cs_in_progress_count())->toBe(1)
        ->and(cs_grants($entryB))->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'consultations.started')->where('subject_id', $consultationB)->count())->toBe(0);
});

it('start B vs call-next on the doctor\'s other queue, simultaneous: the start always commits; the call is either refused DOCTOR_ALREADY_CALLING or CALLED, never both (invariant)', function () {
    [$entryA, $consultationA] = cs_called(test()->headersA, test()->profileA);
    [$otherQueueId, $otherEntry] = wi_second_queue_for_doctor($this->w, 'WAITING');
    $otherQueue = WalkInQueue::query()->findOrFail($otherQueueId);
    $callOther = fn () => cs_attempt(fn () => app(QueueOperations::class)->callNext(test()->staffUser, $otherQueue)->id);

    $results = wi_race([cs_start($consultationA), $callOther]);

    $called = cs_refusal($results[1]) === null;
    expect(cs_refusal($results[0]))->toBeNull()->and($results[0]['ok']['done'])->toBe('IN_PROGRESS')
        ->and(cs_status($consultationA))->toBe('IN_PROGRESS')
        ->and(wi_status($otherEntry))->toBe($called ? 'CALLED' : 'WAITING')
        ->and(cs_in_progress_count())->toBe(1);
    if (! $called) {
        expect(cs_refusal($results[1]))->toBe(DoctorAlreadyCalling::class)->and(cs_code($results[1]))->toBe('DOCTOR_ALREADY_CALLING')->and(cs_reason($results[1]))->toBe('other_queue');
    }
});

it('HAG-30 m6: start vs skip, truly simultaneous (one barrier, no order): exactly one wins; the loser carries its error and reason code; the grant matches the winner', function () {
    [$entry, $consultation] = cs_called($this->headersA, $this->profileA);

    $results = wi_race([cs_start($consultation), cs_skip($entry)]);

    $startWon = cs_refusal($results[0]) === null;
    $skipWon = cs_refusal($results[1]) === null;
    expect((int) $startWon + (int) $skipWon)->toBe(1)
        ->and(cs_grants($entry))->toBe($startWon ? 1 : 0)
        ->and(cs_status($consultation))->toBe($startWon ? 'IN_PROGRESS' : 'CANCELLED')
        ->and(wi_status($entry))->toBe($startWon ? 'IN_CONSULTATION' : 'SKIPPED');
    if ($startWon) {
        expect(cs_refusal($results[1]))->toBe(InvalidEntryTransition::class)->and(cs_code($results[1]))->toBe('INVALID_STATE_TRANSITION')
            ->and(cs_reason($results[1]))->toBe('invalid_transition');
    } else {
        expect(cs_refusal($results[0]))->toBe(ConsultationRefused::class)->and(cs_code($results[0]))->toBe('INVALID_STATE_TRANSITION')
            ->and(cs_reason($results[0]))->toBe('invalid_transition');
    }
});
