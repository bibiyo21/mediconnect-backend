<?php

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\WalkIns\DTOs\RegistrationCommand;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Enums\PayerType;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Exceptions\ConcurrentRegistration;
use App\Modules\WalkIns\Exceptions\DoctorAlreadyCalling;
use App\Modules\WalkIns\Exceptions\DoctorNotAvailable;
use App\Modules\WalkIns\Exceptions\EntryAlreadyCalled;
use App\Modules\WalkIns\Exceptions\EntryAlreadyLive;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\EntryActions;
use App\Modules\WalkIns\Services\LockWait;
use App\Modules\WalkIns\Services\QueueOperations;
use App\Modules\WalkIns\Services\WalkInRegistration;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Concurrency scenario 2 (CLAUDE.md §28.4) for queue operations, increment 5.6-2. Real parallel processes (forked children, one database
 * session each, released together, as in WalkInRegistrationRaceTest) run the application's own services on committed data. The assertions are
 * invariants that hold under any interleaving: one CALLED entry per queue and per doctor, one consultation per call, no CALLED row after a
 * check-out, one held entry per patient, one call per Idempotency-Key. The check-out races hold the doctor's presence row from a second
 * session, as 5.8's check-out will (session FOR UPDATE, then queues).
 *
 * Note: a race whose outcome is the same with or without a lock (2b, the doctor race) does not detect a missing lock, because the other locks and the
 * unique-index backstop still give one winner. The lock-wait tests (advisory 5504, queue row, presence row) and the lock-order proof are what catch a
 * removed lock: each holds the lock from a second session and requires the call to wait.
 */

beforeEach(function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    fresh_test_database();
    $this->seed(RbacSeeder::class);
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    doctor_in($this->w);
    [$this->staffUser, $this->staffHeaders] = clinic_member($this->w['clinic'], Role::ClinicStaff);
    app('auth')->forgetGuards();
    $this->guest = fn (string $name) => register_walkin($this->staffHeaders, guest_body($this->w['service'], $name))->assertCreated()->json('data.id');
    $this->a = ($this->guest)('Alpha Guest');
    $this->b = ($this->guest)('Bravo Guest');
    $this->c = ($this->guest)('Charlie Guest');
    $this->queue = WalkInQueue::query()->firstOrFail();
    app('auth')->forgetGuards();
});

afterEach(function () {
    DB::purge('pgsql_second');
    fresh_test_database();
});

function cn_call_next(): Closure
{
    return fn () => app(QueueOperations::class)->callNext(test()->staffUser, test()->queue)->id;
}

function cn_called(): int
{
    return DB::table('walkin_queue_entries')->where('status', 'CALLED')->count();
}

it('race 2b: two simultaneous call-nexts on one queue make exactly one CALLED, one 409, one consultation and one CALLED event', function () {
    $results = wi_race([cn_call_next(), cn_call_next()]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing(['ok', EntryAlreadyCalled::class])
        ->and(cn_called())->toBe(1)->and(DB::table('consultations')->count())->toBe(1)->and(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'walkins.called')->count())->toBe(1)
        ->and(DB::table('walkin_queue_entries')->where('status', 'CALLED')->value('id'))->toBe($this->a);
});

it('doctor race: call-next on two queues of one doctor calls exactly one patient doctor-wide; the loser gets DOCTOR_ALREADY_CALLING', function () {
    [$second] = wi_second_queue_for_doctor($this->w, 'WAITING');
    $other = WalkInQueue::query()->findOrFail($second);

    $results = wi_race([cn_call_next(), fn () => app(QueueOperations::class)->callNext(test()->staffUser, $other)->id]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing(['ok', DoctorAlreadyCalling::class])
        ->and(cn_called())->toBe(1)->and(DB::table('consultations')->count())->toBe(1)->and(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(1);
});

it('maps the unique-index backstop to DOCTOR_ALREADY_CALLING when the checks could not see the other call (an uncommitted competing call)', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    [$second, $secondEntry] = wi_second_queue_for_doctor($this->w, 'WAITING');
    $competing = DB::connection('pgsql_second');
    $competing->beginTransaction();
    // A call on another queue of the doctor that has not committed: invisible to the child's checks, but it owns the unique key.
    $competing->table('walkin_queue_entries')->where('id', $secondEntry)->update(['status' => 'CALLED', 'assigned_doctor_profile_id' => $this->w['doctor']->id, 'called_at' => now(), 'queue_sort_at' => null]);

    $children = wi_race_with_parent([cn_call_next()], function () use ($competing) {
        usleep(800_000);
        $competing->commit();
    });

    expect($children[0]['error'] ?? null)->toBe(DoctorAlreadyCalling::class)->and($children[0]['elapsed'])->toBeGreaterThan(0.4)
        ->and(cn_called())->toBe(1)->and(DB::table('walkin_queue_entries')->where('status', 'CALLED')->value('id'))->toBe($secondEntry)->and(DB::table('consultations')->count())->toBe(0);
});

it('maps the one-called-per-queue backstop when a competing call on the same queue has not committed', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    $competing = DB::connection('pgsql_second');
    $competing->beginTransaction();
    $competing->table('walkin_queue_entries')->where('id', $this->c)->update(['status' => 'CALLED', 'assigned_doctor_profile_id' => $this->w['doctor']->id, 'called_at' => now(), 'queue_sort_at' => null]);

    $children = wi_race_with_parent([cn_call_next()], function () use ($competing) {
        usleep(800_000);
        $competing->commit();
    });

    // Both unique indexes name the same fact; whichever PostgreSQL reports first, the loser gets a 409 and only one entry is CALLED.
    expect($children[0]['error'] ?? null)->toBeIn([DoctorAlreadyCalling::class, EntryAlreadyCalled::class])->and(cn_called())->toBe(1)->and(DB::table('consultations')->count())->toBe(0);
});

it('call vs cancel of the head: never a CALLED entry that was cancelled, never two CALLED', function () {
    $staff = $this->staffUser;
    $head = WalkInQueueEntry::query()->with('queue')->findOrFail($this->a);

    $results = wi_race([cn_call_next(), fn () => app(EntryActions::class)->cancel($staff, $head, CancelReason::Other, true)->status->value]);

    $outcomes = wi_outcomes($results);
    expect([$outcomes])->toBeIn([[['ok', 'ok']], [['ok', InvalidEntryTransition::class]]])->and(cn_called())->toBe(1);
    $a = DB::table('walkin_queue_entries')->where('id', $this->a)->value('status');
    if ($a === 'CANCELLED') {
        // The cancel won: the call took the next entry.
        expect(DB::table('walkin_queue_entries')->where('status', 'CALLED')->value('id'))->toBe($this->b)->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $this->a)->where('event_type', 'CALLED')->count())->toBe(0);
    } else {
        expect($a)->toBe('CALLED')->and($outcomes[1])->toBe(InvalidEntryTransition::class);
    }
    expect(DB::table('consultations')->where('status', 'SCHEDULED')->count())->toBe(1);
});

it('call vs registration: both succeed, the number is not reused and one entry is called', function () {
    $registrar = $this->staffUser;
    $service = $this->w['service'];

    $results = wi_race([
        cn_call_next(),
        fn () => app(WalkInRegistration::class)->register(new RegistrationCommand($registrar, RegistrationChannel::StaffConsole, DoctorService::findOrFail($service), (string) Str::uuid7(), PayerType::SelfPay, null, 'Delta Guest', null, null, PriorityLevel::Normal, null, null, 'v1', false))->entry->queue_number,
    ]);

    expect(wi_outcomes($results))->toBe(['ok', 'ok'])->and($results[1]['ok'])->toBe(4)
        ->and(DB::table('walkin_queue_entries')->orderBy('queue_number')->pluck('queue_number')->all())->toBe([1, 2, 3, 4])->and(cn_called())->toBe(1);
});

it('recall vs skip: the entry ends SKIPPED with no CALLED row, and the consultation follows', function () {
    $staff = $this->staffUser;
    app(QueueOperations::class)->callNext($staff, $this->queue);
    $called = WalkInQueueEntry::query()->with('queue')->findOrFail($this->a);

    $results = wi_race([fn () => app(QueueOperations::class)->recall($staff, $called)->recall_count, fn () => app(QueueOperations::class)->skip($staff, $called)->status->value]);

    $outcomes = wi_outcomes($results);
    expect([$outcomes])->toBeIn([[['ok', 'ok']], [[InvalidEntryTransition::class, 'ok']]])
        ->and(DB::table('walkin_queue_entries')->where('id', $this->a)->value('status'))->toBe('SKIPPED')->and(cn_called())->toBe(0)
        ->and(DB::table('consultations')->where('walkin_queue_entry_id', $this->a)->value('status'))->toBe('CANCELLED')
        ->and(DB::table('walkin_queue_entries')->where('id', $this->a)->value('assigned_doctor_profile_id'))->toBeNull();
});

it('return vs a patient registering again: still exactly one held entry for the patient', function () {
    $patient = patient_account();
    DB::table('users')->where('id', $patient->id)->update(['mobile_e164' => '+639'.random_int(100000000, 999999999), 'mobile_verified_at' => now()]);
    $profile = PatientProfile::where('user_id', $patient->id)->firstOrFail();
    $staff = $this->staffUser;
    $command = fn () => new RegistrationCommand($patient->refresh(), RegistrationChannel::PatientApp, DoctorService::findOrFail($this->w['service']), (string) Str::uuid7(), PayerType::SelfPay, $profile);
    $id = app(WalkInRegistration::class)->register($command())->entry->id;
    DB::table('walkin_queue_entries')->whereIn('id', [$this->a, $this->b, $this->c])->update(['status' => 'CANCELLED']);
    app(EntryActions::class)->arrive($staff, WalkInQueueEntry::query()->with('queue')->findOrFail($id));
    app(QueueOperations::class)->callNext($staff, $this->queue);
    $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);
    app(QueueOperations::class)->skip($staff, $entry);
    $again = $command();

    $results = wi_race([fn () => app(QueueOperations::class)->returnToQueue($staff, $entry)->status->value, fn () => app(WalkInRegistration::class)->register($again)->entry->id]);

    expect(wi_outcomes($results))->toBe(['ok', EntryAlreadyLive::class])
        ->and(DB::table('walkin_queue_entries')->where('patient_profile_id', $profile->id)->whereIn('status', ['REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION', 'SKIPPED'])->count())->toBe(1);
});

it('call-next vs check-out: the call waits for the doctor\'s session row, is then refused, and no CALLED row remains', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();
    $outAt = $this->clock->now()->addMinute();

    $children = wi_race_with_parent([cn_call_next()], function () use ($second, $outAt) {
        usleep(1_000_000);
        $second->table('doctor_presence_sessions')->whereNull('checked_out_at')->update(['current_status' => 'OUT', 'checked_out_at' => $outAt, 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);
        $second->commit();
    });

    expect($children[0]['error'] ?? null)->toBe(DoctorNotAvailable::class)->and($children[0]['elapsed'])->toBeGreaterThan(0.4)
        ->and(cn_called())->toBe(0)->and(DB::table('consultations')->count())->toBe(0)->and(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(0);
});

it('call-next past the lock wait answers CONCURRENT_MODIFICATION and leaves no CALLED row', function () {
    config(['walkins.lock_timeout_ms' => 300]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();

    expect(fn () => app(QueueOperations::class)->callNext($this->staffUser, $this->queue))->toThrow(ConcurrentRegistration::class);
    $second->rollBack();

    expect(cn_called())->toBe(0)->and(DB::table('consultations')->count())->toBe(0);
    app(QueueOperations::class)->callNext($this->staffUser, $this->queue);
    expect(cn_called())->toBe(1);
});

it('a replayed Idempotency-Key makes no second call, also when both requests arrive at once', function () {
    $key = (string) Str::uuid7();
    $queue = $this->queue->id;
    $headers = $this->staffHeaders;
    $send = fn () => test()->postJson("/api/v1/walk-ins/queues/{$queue}/call-next", [], [...$headers, 'Idempotency-Key' => $key])->status();

    $results = wi_race([$send, $send]);

    expect(cn_called())->toBe(1)->and(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(1)->and(DB::table('consultations')->count())->toBe(1)
        ->and(collect($results)->pluck('ok')->filter()->contains(200))->toBeTrue();
    app('auth')->forgetGuards();
    test()->postJson("/api/v1/walk-ins/queues/{$queue}/call-next", [], [...$headers, 'Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(1);
});

it('maps a unique violation on return to ENTRY_ALREADY_LIVE with the other entry (the held-entry index bypassed)', function () {
    $patient = patient_account();
    DB::table('users')->where('id', $patient->id)->update(['mobile_e164' => '+639'.random_int(100000000, 999999999), 'mobile_verified_at' => now()]);
    $profile = PatientProfile::where('user_id', $patient->id)->firstOrFail();
    $staff = $this->staffUser;
    $id = app(WalkInRegistration::class)->register(new RegistrationCommand($patient->refresh(), RegistrationChannel::PatientApp, DoctorService::findOrFail($this->w['service']), (string) Str::uuid7(), PayerType::SelfPay, $profile))->entry->id;
    DB::table('walkin_queue_entries')->whereIn('id', [$this->a, $this->b, $this->c])->update(['status' => 'CANCELLED']);
    app(EntryActions::class)->arrive($staff, WalkInQueueEntry::query()->with('queue')->findOrFail($id));
    app(QueueOperations::class)->callNext($staff, $this->queue);
    $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);
    app(QueueOperations::class)->skip($staff, $entry);
    // Only reachable if the held-entry guard were bypassed: drop it (as the table owner) and give the patient a second live entry.
    DB::connection('pgsql_owner')->statement('DROP INDEX uq_one_held_entry_per_patient');
    $other = (string) Str::uuid7();
    DB::table('walkin_queue_entries')->insert([
        'id' => $other, 'walkin_queue_id' => $this->queue->id, 'queue_number' => 99, 'patient_profile_id' => $profile->id, 'registered_by_user_id' => $patient->id, 'registration_channel' => 'PATIENT_APP',
        'status' => 'WAITING', 'payer_type' => 'SELF_PAY', 'doctor_service_id' => $this->w['service'], 'arrived_at' => now(), 'queue_sort_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        app(QueueOperations::class)->returnToQueue($staff, $entry);
        $caught = null;
    } catch (EntryAlreadyLive $e) {
        $caught = $e->entryId;
    }

    expect($caught)->toBe($other)->and(DB::table('walkin_queue_entries')->where('id', $id)->value('status'))->toBe('SKIPPED');
});

it('a call waits for the doctor-call advisory lock held by another session, then goes through (D6)', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->select('SELECT pg_advisory_xact_lock(?, hashtext(?))', [LockWait::DOCTOR_CALL, $this->w['doctor']->id]);

    $children = wi_race_with_parent([cn_call_next()], function () use ($second) {
        usleep(800_000);
        $second->rollBack();
    });

    expect(wi_outcomes($children))->toBe(['ok'])->and($children[0]['elapsed'])->toBeGreaterThan(0.4)->and(cn_called())->toBe(1);
});

it('a call waits for the queue row lock held by another session, then goes through', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    // FOR NO KEY UPDATE conflicts with the call's FOR UPDATE but not with the key-share lock that an event row's foreign key takes: only the explicit lock makes the call wait.
    $second->select('SELECT id FROM walkin_queues WHERE id = ? FOR NO KEY UPDATE', [$this->queue->id]);

    $children = wi_race_with_parent([cn_call_next()], function () use ($second) {
        usleep(800_000);
        $second->rollBack();
    });

    expect(wi_outcomes($children))->toBe(['ok'])->and($children[0]['elapsed'])->toBeGreaterThan(0.4)->and(cn_called())->toBe(1);
});

it('lock order proof: a call blocked on the queue row already holds the presence row FOR SHARE and the 5504 advisory lock', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->select('SELECT id FROM walkin_queues WHERE id = ? FOR NO KEY UPDATE', [$this->queue->id]);
    $proof = [];
    $doctor = $this->w['doctor']->id;

    $children = wi_race_with_parent([cn_call_next()], function () use ($second, &$proof, $doctor) {
        usleep(600_000);
        $own = (int) $second->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        // The advisory lock (class 5504) is granted to another backend, not this session.
        $proof['advisory'] = (int) $second->selectOne('SELECT count(*) AS n FROM pg_locks WHERE locktype = \'advisory\' AND classid = ? AND granted AND pid <> ?', [LockWait::DOCTOR_CALL, $own])->n;
        // A competing FOR UPDATE on the presence row cannot be had while the call holds it FOR SHARE.
        $proof['share'] = false;
        $second->statement('SAVEPOINT probe');
        try {
            $second->select('SELECT id FROM doctor_presence_sessions WHERE doctor_profile_id = ? AND checked_out_at IS NULL FOR UPDATE NOWAIT', [$doctor]);
        } catch (Throwable) {
            $proof['share'] = true;
        }
        $second->statement('ROLLBACK TO SAVEPOINT probe');
        $second->rollBack();
    });

    expect(wi_outcomes($children))->toBe(['ok'])->and($proof['advisory'])->toBe(1)->and($proof['share'])->toBeTrue();
});
