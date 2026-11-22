<?php

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.1: presence, walk-in and consultation tables enforce the
 * invariants behind the Phase 5 exit scenarios themselves (CLAUDE.md §8, §9,
 * §28.4 scenarios 2, 3 and 12). Raw inserts on purpose: these must hold even
 * if every service-layer check has a bug.
 */

/** Runs $write in a savepoint and returns the SQLSTATE it failed with (null when it succeeded). */
function schema_rejects(callable $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/** Like schema_rejects, but returns the database message (it names the violated constraint or index). */
function schema_error(callable $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return null;
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->location = directory_clinic();
    $this->doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $this->location);
    $this->user = (string) $this->doctor->user_id;
    $this->patient = (string) PatientProfile::where('user_id', $this->user)->value('id');
    $this->newPatient = fn (): string => (string) PatientProfile::where('user_id', patient_account()->id)->value('id');
    $this->patient2 = ($this->newPatient)();
    $this->doctor2 = directory_doctor('ACTIVE', 'Dr. Ben Reyes', $this->location);

    $this->session = function (array $extra = [], ?string $doctor = null): string {
        $id = (string) Str::uuid7();
        DB::table('doctor_presence_sessions')->insert([
            'id' => $id, 'doctor_profile_id' => $doctor ?? $this->doctor->id, 'clinic_location_id' => $this->location->id, 'session_kind' => 'IN_PERSON',
            'current_status' => 'IN', 'checked_in_at' => '2026-12-01 01:00:00+00', 'checked_in_by' => $this->user, ...$extra,
        ]);

        return $id;
    };
    $this->closed = fn (array $extra = []): array => [
        'current_status' => 'OUT', 'checked_out_at' => '2026-12-01 09:00:00+00', 'checkout_reason' => 'END_OF_SHIFT',
        'checked_out_method' => 'DOCTOR_APP', 'checked_out_by' => $this->user, ...$extra,
    ];
    $this->event = fn (string $session, array $extra = []) => DB::table('doctor_presence_events')->insert([
        'doctor_presence_session_id' => $session, 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->location->id,
        'previous_status' => 'NOT_CHECKED_IN', 'new_status' => 'IN', 'changed_by' => $this->user, 'change_method' => 'DOCTOR_APP', ...$extra,
    ]);

    $this->queue = function (array $extra = []): string {
        $id = (string) Str::uuid7();
        DB::table('walkin_queues')->insert(['id' => $id, 'clinic_location_id' => $this->location->id, 'doctor_profile_id' => $this->doctor->id, 'local_date' => '2026-12-01', ...$extra]);

        return $id;
    };
    $this->entry = function (string $queue, int $number, ?string $patient, string $status = 'WAITING', array $extra = []): string {
        // What a status needs (the coherence CHECK): a call names the doctor and the time.
        $needs = match ($status) {
            'CALLED' => ['called_at' => '2026-12-01 02:00:00+00', 'assigned_doctor_profile_id' => $this->doctor->id],
            'IN_CONSULTATION' => ['called_at' => '2026-12-01 02:00:00+00', 'consultation_started_at' => '2026-12-01 02:01:00+00', 'assigned_doctor_profile_id' => $this->doctor->id],
            'COMPLETED' => ['completed_at' => '2026-12-01 02:30:00+00'],
            default => [],
        };
        $id = (string) Str::uuid7();
        DB::table('walkin_queue_entries')->insert([
            'id' => $id, 'walkin_queue_id' => $queue, 'queue_number' => $number, 'patient_profile_id' => $patient,
            'guest_name_encrypted' => $patient === null ? 'cipher' : null, 'registered_by_user_id' => $this->user,
            'registration_channel' => 'STAFF_CONSOLE', 'status' => $status, 'payer_type' => 'SELF_PAY', ...$needs, ...$extra,
        ]);

        return $id;
    };
    $this->queueEvent = fn (string $queue, ?string $entry, string $type, array $extra = []) => DB::table('walkin_queue_events')->insert(['walkin_queue_id' => $queue, 'walkin_queue_entry_id' => $entry, 'event_type' => $type, ...$extra]);

    $this->consultation = function (array $extra = []): string {
        $id = (string) Str::uuid7();
        DB::table('consultations')->insert([
            'id' => $id, 'doctor_profile_id' => $this->doctor->id, 'patient_profile_id' => $this->patient, 'clinic_location_id' => $this->location->id,
            'consultation_type' => 'IN_PERSON', 'status' => 'SCHEDULED', ...$extra,
        ]);

        return $id;
    };
    $this->appointment = function (array $extra = []): string {
        $service = (string) DoctorService::where('doctor_profile_id', $this->doctor->id)->where('consultation_type', 'IN_PERSON')->value('id');
        $mode = (string) Str::uuid7();
        DB::table('doctor_service_modes')->insert(['id' => $mode, 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->location->id, 'doctor_service_id' => $service, 'consultation_type' => 'IN_PERSON', 'service_mode' => 'BOTH', 'effective_from' => '2026-11-01']);
        $id = (string) Str::uuid7();
        DB::table('appointments')->insert([
            'id' => $id, 'reference_code' => strtoupper(Str::random(8)), 'patient_profile_id' => $this->patient, 'booked_by_user_id' => $this->user,
            'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->location->id, 'doctor_service_id' => $service, 'doctor_service_mode_id' => $mode,
            'consultation_type' => 'IN_PERSON', 'starts_at' => '2026-12-02 01:00:00+00', 'ends_at' => '2026-12-02 01:20:00+00', 'timezone' => 'Asia/Manila',
            'status' => 'CONFIRMED', 'payer_type' => 'SELF_PAY', 'fee_amount' => 65000, ...$extra,
        ]);

        return $id;
    };
});

it('allows one open presence session per doctor; a closed one frees the doctor', function () {
    $first = ($this->session)();

    expect(schema_error(fn () => ($this->session)()))->toContain('uq_one_open_presence_session')
        ->and(schema_rejects(fn () => ($this->session)([], $this->doctor2->id)))->toBeNull(); // another doctor

    DB::table('doctor_presence_sessions')->where('id', $first)->update(($this->closed)());
    expect(schema_rejects(fn () => ($this->session)()))->toBeNull();
});

it('keeps a doctor in one place: an online session has no location, an in-person one has', function () {
    expect(schema_rejects(fn () => ($this->session)(['session_kind' => 'ONLINE'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->session)(['session_kind' => 'IN_PERSON', 'clinic_location_id' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->session)(['session_kind' => 'ONLINE', 'clinic_location_id' => null])))->toBeNull();
});

it('stores only IN, ON_BREAK, TEMPORARILY_UNAVAILABLE and OUT, and ties OUT to a closed session', function () {
    expect(schema_rejects(fn () => ($this->session)(['current_status' => 'BUSY'])))->toBe('23514') // derived, never stored
        ->and(schema_rejects(fn () => ($this->session)(['current_status' => 'NOT_CHECKED_IN'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->session)(['current_status' => 'OUT'])))->toBe('23514') // OUT but not closed
        ->and(schema_rejects(fn () => ($this->session)(['checked_out_at' => '2026-12-01 09:00:00+00', 'checkout_reason' => 'END_OF_SHIFT'])))->toBe('23514') // closed but still IN
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checkout_reason' => null]))))->toBe('23514') // closed without a reason
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checked_out_at' => '2026-12-01 00:00:00+00']))))->toBe('23514') // out before in
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checkout_reason' => 'NAP']))))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->session)(['current_status' => 'ON_BREAK'])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(), $this->doctor2->id)))->toBeNull();
});

it('records who and how a session was closed, and nothing of the kind on an open one', function () {
    expect(schema_rejects(fn () => ($this->session)(['checked_out_by' => $this->user])))->toBe('23514') // open session with a checkout actor
        ->and(schema_rejects(fn () => ($this->session)(['checked_out_method' => 'DOCTOR_APP'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checked_out_method' => null]))))->toBe('23514') // closed without a method
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checked_out_by' => null]))))->toBe('23514') // a person's method needs the person
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checked_out_method' => 'SYSTEM']), $this->doctor2->id)))->toBe('23514') // SYSTEM has no actor
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checked_out_method' => 'SYSTEM', 'checked_out_by' => null, 'checkout_reason' => 'CLINIC_CLOSED']))))->toBeNull()
        ->and(schema_rejects(fn () => ($this->session)(($this->closed)(['checked_out_method' => 'TELEPATHY']), $this->doctor2->id)))->toBe('23514');
});

it('allows a break end time only while ON_BREAK', function () {
    expect(schema_rejects(fn () => ($this->session)(['current_break_expected_end_at' => '2026-12-01 02:00:00+00'])))->toBe('23514') // IN
        ->and(schema_rejects(fn () => ($this->session)(['current_status' => 'TEMPORARILY_UNAVAILABLE', 'current_break_expected_end_at' => '2026-12-01 02:00:00+00'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->session)(['current_status' => 'ON_BREAK', 'current_break_expected_end_at' => '2026-12-01 02:00:00+00'])))->toBeNull();
});

it('keeps presence events well-formed: an actor unless SYSTEM, a real change, and the session\'s own doctor and location', function () {
    $session = ($this->session)();
    ($this->event)($session);

    expect(schema_rejects(fn () => ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'BUSY'])))->toBe('23514') // BUSY is derived
        ->and(schema_rejects(fn () => ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'OUT', 'change_method' => 'GEOFENCE'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'IN'])))->toBe('23514') // no change
        ->and(schema_rejects(fn () => ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'OUT', 'change_method' => 'SYSTEM'])))->toBe('23514') // SYSTEM with an actor
        ->and(schema_rejects(fn () => ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'OUT', 'changed_by' => null])))->toBe('23514') // a person's method without the person
        ->and(schema_rejects(fn () => ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'OUT', 'change_method' => 'SYSTEM', 'changed_by' => null])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->event)($session, ['doctor_profile_id' => $this->doctor2->id, 'previous_status' => 'IN', 'new_status' => 'ON_BREAK'])))->toBe('23514') // another doctor
        ->and(schema_rejects(fn () => ($this->event)($session, ['clinic_location_id' => null, 'previous_status' => 'IN', 'new_status' => 'ON_BREAK'])))->toBe('23514') // another place
        ->and(schema_rejects(fn () => ($this->event)((string) Str::uuid7())))->not->toBeNull(); // no such session

    $online = ($this->session)(['session_kind' => 'ONLINE', 'clinic_location_id' => null], $this->doctor2->id);
    expect(schema_rejects(fn () => ($this->event)($online, ['doctor_profile_id' => $this->doctor2->id])))->toBe('23514') // an online session has no location
        ->and(schema_rejects(fn () => ($this->event)($online, ['doctor_profile_id' => $this->doctor2->id, 'clinic_location_id' => null])))->toBeNull();
});

it('stamps events with the statement clock, so order inside one transaction is kept', function () {
    $session = ($this->session)();
    DB::transaction(function () use ($session) {
        ($this->event)($session);
        usleep(2000);
        ($this->event)($session, ['previous_status' => 'IN', 'new_status' => 'ON_BREAK']);
    });
    // Compared in SQL: PHP would round to whole seconds.
    $later = DB::selectOne('SELECT (SELECT occurred_at FROM doctor_presence_events ORDER BY id DESC LIMIT 1) > (SELECT occurred_at FROM doctor_presence_events ORDER BY id ASC LIMIT 1) AS later')->later;

    expect($later)->toBeTrue()
        ->and(DB::selectOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'doctor_presence_events' AND column_name = 'occurred_at'")->column_default)->toContain('clock_timestamp');
});

it('keeps presence and walk-in events append-only for the application role (UPDATE, DELETE and TRUNCATE)', function () {
    $session = ($this->session)();
    ($this->event)($session);
    $queue = ($this->queue)();
    ($this->queueEvent)($queue, null, 'QUEUE_OPENED');
    $role = (string) config('database.connections.pgsql.username');

    foreach (['doctor_presence_events', 'walkin_queue_events'] as $table) {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            expect(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', [$role, $table, $privilege])->allowed)->toBeFalse("{$table} {$privilege}");
        }
        // Even a privilege grant cannot reopen it: the trigger is the second layer.
        expect(DB::selectOne('SELECT count(*) AS n FROM pg_trigger WHERE tgname = ? AND NOT tgisinternal', ["{$table}_append_only"])->n)->toBe(1)
            ->and(schema_rejects(fn () => DB::table($table)->update(['reason' => 'edited'])))->toBe('42501')
            ->and(schema_rejects(fn () => DB::table($table)->delete()))->toBe('42501')
            ->and(schema_rejects(fn () => DB::statement("TRUNCATE {$table}")))->toBe('42501')
            ->and(DB::table($table)->count())->toBe(1);
    }
});

it('allows one queue per location, doctor, service and day, including shared-pool queues', function () {
    ($this->queue)();

    expect(schema_error(fn () => ($this->queue)()))->toContain('uq_walkin_queue_per_day')
        ->and(schema_rejects(fn () => ($this->queue)(['local_date' => '2026-12-02'])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->queue)(['doctor_profile_id' => null])))->toBeNull()
        // NULL doctor must still collide with itself (the COALESCE in the unique index).
        ->and(schema_rejects(fn () => ($this->queue)(['doctor_profile_id' => null])))->toBe('23505')
        ->and(schema_rejects(fn () => ($this->queue)(['status' => 'CLOSED'])))->toBe('23514'); // CLOSED needs closed_at
});

it('clears who closed a queue when it reopens', function () {
    $queue = ($this->queue)(['status' => 'CLOSED', 'closed_at' => '2026-12-01 10:00:00+00', 'closed_by' => $this->user]);

    expect(schema_rejects(fn () => DB::table('walkin_queues')->where('id', $queue)->update(['status' => 'OPEN', 'closed_at' => null])))->toBe('23514') // stale closed_by
        ->and(schema_rejects(fn () => DB::table('walkin_queues')->where('id', $queue)->update(['status' => 'OPEN', 'closed_at' => null, 'closed_by' => null])))->toBeNull();
});

it('rejects a duplicate queue number in one queue', function () {
    $queue = ($this->queue)();
    ($this->entry)($queue, 1, $this->patient);
    $other = ($this->queue)(['local_date' => '2026-12-02']);

    expect(schema_error(fn () => ($this->entry)($queue, 1, $this->patient2)))->toContain('uq_queue_number')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 2, $this->patient2)))->toBeNull()
        ->and(schema_rejects(fn () => ($this->entry)($other, 1, $this->patient)))->toBeNull() // numbers restart per queue
        ->and(schema_rejects(fn () => ($this->entry)($queue, 0, ($this->newPatient)())))->toBe('23514');
});

it('rejects a second live entry for the same patient in one queue, but not once the first has ended', function () {
    // A fresh queue and patient per case: no case depends on another.
    foreach (['REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION'] as $i => $status) {
        $queue = ($this->queue)(['local_date' => '2027-01-0'.($i + 1)]);
        $patient = ($this->newPatient)();
        ($this->entry)($queue, 1, $patient);

        // Called entries name doctor2 so only the one-live-entry index can reject them.
        expect(schema_error(fn () => ($this->entry)($queue, 2, $patient, $status, ['assigned_doctor_profile_id' => $this->doctor2->id])))->toContain('uq_one_live_entry_per_patient');
    }

    foreach (['COMPLETED', 'SKIPPED', 'NO_SHOW', 'CANCELLED'] as $i => $status) {
        $queue = ($this->queue)(['local_date' => '2027-02-0'.($i + 1)]);
        $patient = ($this->newPatient)();
        $first = ($this->entry)($queue, 1, $patient);
        DB::table('walkin_queue_entries')->where('id', $first)->update(['status' => $status, 'completed_at' => $status === 'COMPLETED' ? '2026-12-01 02:30:00+00' : null]);

        expect(schema_rejects(fn () => ($this->entry)($queue, 2, $patient)))->toBeNull();
    }
});

it('does not limit guests per queue, and requires exactly one identity per entry', function () {
    $queue = ($this->queue)();

    expect(schema_rejects(fn () => ($this->entry)($queue, 1, null)))->toBeNull()
        ->and(schema_rejects(fn () => ($this->entry)($queue, 2, null)))->toBeNull()
        // A patient profile and a guest name together, or neither, is rejected.
        ->and(schema_rejects(fn () => ($this->entry)($queue, 3, $this->patient, 'WAITING', ['guest_name_encrypted' => 'cipher'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 4, null, 'WAITING', ['guest_name_encrypted' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 5, $this->patient, 'WAITING', ['guest_phone_encrypted' => 'cipher'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 6, $this->patient, 'WAITING', ['guest_phone_hash' => hash('sha256', 'p')])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 7, $this->patient, 'WAITING', ['guest_pii_purged_at' => '2026-12-31 00:00:00+00'])))->toBe('23514');
});

it('lets the retention job purge guest data in place, and nothing else erase identity', function () {
    $queue = ($this->queue)();
    $guest = ($this->entry)($queue, 1, null, 'WAITING', ['guest_phone_encrypted' => 'cipher', 'guest_phone_hash' => hash('sha256', '+639171234567')]);
    $registered = ($this->entry)($queue, 2, $this->patient);
    $purge = ['guest_name_encrypted' => null, 'guest_phone_encrypted' => null, 'guest_phone_hash' => null, 'guest_pii_purged_at' => '2027-01-15 00:00:00+00'];

    // Erasing the name without recording the purge would leave an identity-less row.
    expect(schema_rejects(fn () => DB::table('walkin_queue_entries')->where('id', $guest)->update(['guest_name_encrypted' => null])))->toBe('23514')
        // A purge stamp with data still present, or on a registered patient, is rejected.
        ->and(schema_rejects(fn () => DB::table('walkin_queue_entries')->where('id', $guest)->update(['guest_pii_purged_at' => '2027-01-15 00:00:00+00'])))->toBe('23514')
        ->and(schema_rejects(fn () => DB::table('walkin_queue_entries')->where('id', $registered)->update($purge)))->toBe('23514')
        ->and(schema_rejects(fn () => DB::table('walkin_queue_entries')->where('id', $guest)->update($purge)))->toBeNull();

    $row = (array) DB::table('walkin_queue_entries')->where('id', $guest)->first();
    expect($row['guest_name_encrypted'])->toBeNull()->and($row['guest_phone_hash'])->toBeNull()->and($row['guest_pii_purged_at'])->not->toBeNull()
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname = 'ix_queue_phone'")->n)->toBe(1);
});

it('rejects a second CALLED entry per queue and per doctor (recall instead), and frees it once the call moves on', function () {
    $queue = ($this->queue)();
    $otherDoctorQueue = ($this->queue)(['doctor_profile_id' => $this->doctor2->id]);
    $otherDayQueue = ($this->queue)(['local_date' => '2026-12-02']);
    [$p1, $p2, $p3, $p4] = [($this->newPatient)(), ($this->newPatient)(), ($this->newPatient)(), ($this->newPatient)()];
    $called = ($this->entry)($queue, 1, $p1, 'CALLED');

    expect(schema_error(fn () => ($this->entry)($queue, 2, $p2, 'CALLED', ['assigned_doctor_profile_id' => $this->doctor2->id])))->toContain('uq_one_called_entry_per_queue')
        ->and(schema_error(fn () => ($this->entry)($otherDayQueue, 1, $p3, 'CALLED')))->toContain('uq_one_called_entry_per_doctor') // same doctor, another queue
        ->and(schema_rejects(fn () => ($this->entry)($queue, 3, $p4, 'WAITING')))->toBeNull()
        ->and(schema_rejects(fn () => ($this->entry)($otherDoctorQueue, 1, $p3, 'CALLED', ['assigned_doctor_profile_id' => $this->doctor2->id])))->toBeNull(); // another doctor and queue

    DB::table('walkin_queue_entries')->where('id', $called)->update(['status' => 'IN_CONSULTATION', 'consultation_started_at' => '2026-12-01 02:01:00+00']);
    expect(schema_rejects(fn () => ($this->entry)($queue, 5, null, 'CALLED')))->toBeNull();
});

it('requires an entry to carry what its status means: called time and doctor, start, completion', function () {
    $queue = ($this->queue)();
    $p = fn () => ($this->newPatient)();

    expect(schema_rejects(fn () => ($this->entry)($queue, 1, $p(), 'CALLED', ['assigned_doctor_profile_id' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 2, $p(), 'CALLED', ['called_at' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 3, $p(), 'IN_CONSULTATION', ['consultation_started_at' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 4, $p(), 'IN_CONSULTATION', ['assigned_doctor_profile_id' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 5, $p(), 'COMPLETED', ['completed_at' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 6, $p(), 'COMPLETED')))->toBeNull();
});

it('keeps estimates a well-formed range and the triage priority bounded', function () {
    $queue = ($this->queue)();

    expect(schema_rejects(fn () => ($this->entry)($queue, 1, ($this->newPatient)(), 'WAITING', ['estimated_call_at_min' => '2026-12-01 02:00:00+00'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 2, ($this->newPatient)(), 'WAITING', ['estimated_call_at_min' => '2026-12-01 02:00:00+00', 'estimated_call_at_max' => '2026-12-01 01:00:00+00'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 3, ($this->newPatient)(), 'WAITING', ['triage_priority' => 10])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->entry)($queue, 4, ($this->newPatient)(), 'WAITING', ['estimated_call_at_min' => '2026-12-01 02:00:00+00', 'estimated_call_at_max' => '2026-12-01 02:30:00+00'])))->toBeNull();
});

it('takes an HMO membership as a plain id with no foreign key until Phase 7A', function () {
    $queue = ($this->queue)();

    expect(schema_rejects(fn () => ($this->entry)($queue, 1, $this->patient, 'WAITING', ['payer_type' => 'HMO', 'hmo_membership_id' => (string) Str::uuid7()])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->entry)($queue, 2, $this->patient2, 'WAITING', ['payer_type' => 'HMO'])))->toBeNull() // no payer CHECK (D-20)
        ->and(schema_rejects(fn () => ($this->entry)($queue, 3, null, 'WAITING', ['payer_type' => 'CASH'])))->toBe('23514');

    $foreignKeys = DB::table('information_schema.table_constraints')->where('table_name', 'walkin_queue_entries')->where('constraint_type', 'FOREIGN KEY')->pluck('constraint_name')->implode(',');
    expect($foreignKeys)->not->toContain('hmo_membership');
});

it('pairs walk-in events with their entry and queue, and limits statuses to entry statuses', function () {
    $queue = ($this->queue)();
    $other = ($this->queue)(['local_date' => '2026-12-02']);
    $entry = ($this->entry)($queue, 1, $this->patient);

    expect(schema_rejects(fn () => ($this->queueEvent)($queue, $entry, 'REGISTERED', ['to_status' => 'WAITING', 'actor_id' => $this->user])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->queueEvent)($queue, null, 'QUEUE_OPENED')))->toBeNull()
        ->and(schema_rejects(fn () => ($this->queueEvent)($queue, $entry, 'QUEUE_CLOSED')))->toBe('23514') // queue-level event naming an entry
        ->and(schema_rejects(fn () => ($this->queueEvent)($queue, null, 'CALLED')))->toBe('23514') // entry event without an entry
        ->and(schema_rejects(fn () => ($this->queueEvent)($other, $entry, 'REGISTERED')))->toBe('23503') // another queue's entry
        ->and(schema_rejects(fn () => ($this->queueEvent)($queue, $entry, 'TELEPORTED')))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->queueEvent)($queue, $entry, 'CALLED', ['from_status' => 'WAITING', 'to_status' => 'OPEN'])))->toBe('23514') // a queue status is not an entry status
        ->and(schema_rejects(fn () => ($this->queueEvent)($queue, $entry, 'CALLED', ['from_status' => 'WAITING', 'to_status' => 'CALLED'])))->toBeNull();
});

it('rejects a second IN_PROGRESS consultation for one doctor (scenarios 3 and 12 at the database layer)', function () {
    $queue = ($this->queue)();
    $e1 = ($this->entry)($queue, 1, $this->patient, 'IN_CONSULTATION');
    $e2 = ($this->entry)($queue, 2, $this->patient2, 'CALLED');
    $first = ($this->consultation)(['walkin_queue_entry_id' => $e1, 'status' => 'IN_PROGRESS', 'started_at' => '2026-12-01 02:00:00+00']);
    $guest = fn (int $n, string $doctor) => ($this->entry)($queue, $n, null, 'WAITING', ['assigned_doctor_profile_id' => $doctor]);

    expect(schema_error(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e2, 'patient_profile_id' => $this->patient2, 'status' => 'IN_PROGRESS', 'started_at' => '2026-12-01 02:05:00+00'])))->toContain('uq_one_in_progress_consultation')
        // Scheduled, interrupted or finished consultations do not block, and neither does another doctor.
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e2, 'patient_profile_id' => $this->patient2, 'status' => 'SCHEDULED'])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $guest(3, (string) $this->doctor->id), 'patient_profile_id' => null, 'status' => 'INTERRUPTED', 'started_at' => '2026-12-01 01:00:00+00'])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->consultation)(['doctor_profile_id' => $this->doctor2->id, 'walkin_queue_entry_id' => $guest(4, (string) $this->doctor2->id), 'patient_profile_id' => null, 'status' => 'IN_PROGRESS', 'started_at' => '2026-12-01 02:00:00+00'])))->toBeNull();

    DB::table('consultations')->where('id', $first)->update(['status' => 'COMPLETED', 'ended_at' => '2026-12-01 02:20:00+00', 'duration_seconds' => 1200]);
    expect(schema_rejects(fn () => DB::table('consultations')->where('walkin_queue_entry_id', $e2)->update(['status' => 'IN_PROGRESS', 'started_at' => '2026-12-01 02:25:00+00'])))->toBeNull();
});

it('requires a consultation to have exactly one source', function () {
    $queue = ($this->queue)();
    $entry = ($this->entry)($queue, 1, $this->patient);
    $appointment = ($this->appointment)();

    expect(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment, 'walkin_queue_entry_id' => $entry])))->toBe('23514') // both
        ->and(schema_rejects(fn () => ($this->consultation)()))->toBe('23514') // neither
        ->and(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment, 'status' => 'CANCELLED'])))->toBe('23505') // one consultation per source
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $entry])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $entry, 'status' => 'CANCELLED'])))->toBe('23505');
});

it('lets only a walk-in consultation have no patient profile (guest)', function () {
    $queue = ($this->queue)();
    $guest = ($this->entry)($queue, 1, null);

    expect(schema_rejects(fn () => ($this->consultation)(['appointment_id' => ($this->appointment)(), 'patient_profile_id' => null])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $guest, 'patient_profile_id' => null])))->toBeNull();
});

it('requires a walk-in consultation to match its entry: patient, location and doctor', function () {
    $queue = ($this->queue)();
    $second = directory_clinic('Other Clinic');
    $entry = ($this->entry)($queue, 1, $this->patient);
    $guest = ($this->entry)($queue, 2, null);
    $assigned = ($this->entry)($queue, 3, $this->patient2, 'WAITING', ['assigned_doctor_profile_id' => $this->doctor2->id]);

    expect(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $entry, 'patient_profile_id' => $this->patient2])))->toBe('23514') // someone else's visit
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $guest, 'patient_profile_id' => $this->patient])))->toBe('23514') // a patient on a guest visit
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $entry, 'clinic_location_id' => $second->id])))->toBe('23514') // another location
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $entry, 'doctor_profile_id' => $this->doctor2->id])))->toBe('23514') // another doctor
        // The entry's assigned doctor wins over the queue's.
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $assigned, 'patient_profile_id' => $this->patient2])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $assigned, 'patient_profile_id' => $this->patient2, 'doctor_profile_id' => $this->doctor2->id])))->toBeNull()
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $entry])))->toBeNull();

    // Changing a matched column later is checked too.
    $consultation = DB::table('consultations')->where('walkin_queue_entry_id', $entry)->value('id');
    expect(schema_rejects(fn () => DB::table('consultations')->where('id', $consultation)->update(['patient_profile_id' => $this->patient2])))->toBe('23514');
});

it('requires an appointment consultation to match its appointment: patient, doctor, location and type', function () {
    $appointment = ($this->appointment)();
    $second = directory_clinic('Other Clinic');

    expect(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment, 'patient_profile_id' => $this->patient2])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment, 'doctor_profile_id' => $this->doctor2->id])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment, 'clinic_location_id' => $second->id])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment, 'clinic_location_id' => null, 'consultation_type' => 'ONLINE'])))->toBe('23514') // an in-person appointment is not online
        ->and(schema_rejects(fn () => ($this->consultation)(['appointment_id' => $appointment])))->toBeNull();
});

it('keeps consultation facts consistent: location, start, order, duration and completion', function () {
    $queue = ($this->queue)();
    $e = fn (int $n) => ($this->entry)($queue, $n, null);
    $guest = ['patient_profile_id' => null];

    expect(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(1), ...$guest, 'clinic_location_id' => null])))->toBe('23514') // in person needs a location
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(2), ...$guest, 'consultation_type' => 'ONLINE'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(3), ...$guest, 'status' => 'IN_PROGRESS'])))->toBe('23514') // in progress needs started_at
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(4), ...$guest, 'started_at' => '2026-12-01 02:00:00+00', 'ended_at' => '2026-12-01 01:00:00+00'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(5), ...$guest, 'duration_seconds' => -1])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(6), ...$guest, 'status' => 'BUSY'])))->toBe('23514')
        // Completed needs its start, end and duration.
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(7), ...$guest, 'status' => 'COMPLETED', 'started_at' => '2026-12-01 02:00:00+00', 'ended_at' => '2026-12-01 02:20:00+00'])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(8), ...$guest, 'status' => 'COMPLETED', 'ended_at' => '2026-12-01 02:20:00+00', 'duration_seconds' => 1200])))->toBe('23514')
        ->and(schema_rejects(fn () => ($this->consultation)(['walkin_queue_entry_id' => $e(9), ...$guest, 'status' => 'COMPLETED', 'started_at' => '2026-12-01 02:00:00+00', 'ended_at' => '2026-12-01 02:20:00+00', 'duration_seconds' => 1200])))->toBeNull();
});

it('allows one participant row per user and one care relationship per source, and ties a care relationship to its source', function () {
    $queue = ($this->queue)();
    $entry = ($this->entry)($queue, 1, $this->patient);
    $consultation = ($this->consultation)(['walkin_queue_entry_id' => $entry]);
    $participant = fn (string $role = 'DOCTOR') => DB::table('consultation_participants')->insert(['id' => (string) Str::uuid7(), 'consultation_id' => $consultation, 'user_id' => $this->user, 'role' => $role]);
    $care = fn (string $source, array $extra = []) => DB::table('care_relationships')->insert([
        'id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctor->id, 'patient_profile_id' => $this->patient, 'source_type' => 'WALK_IN', 'source_id' => $source,
        'starts_at' => '2026-12-01 02:00:00+00', 'access_until' => '2026-12-08 16:00:00+00', ...$extra,
    ]);
    $n = 10;
    $another = function (?string $patient = null) use ($queue, &$n): string { // an ended entry of this patient, as a fresh source
        return ($this->entry)($queue, $n++, $patient ?? $this->patient, 'CANCELLED');
    };
    $appointment = ($this->appointment)();

    expect(schema_rejects(fn () => $participant()))->toBeNull()
        ->and(schema_rejects(fn () => $participant()))->toBe('23505')
        ->and(schema_rejects(fn () => $participant('NURSE')))->toBe('23514')
        ->and(schema_rejects(fn () => $care($entry)))->toBeNull()
        ->and(schema_rejects(fn () => $care($entry)))->toBe('23505')
        ->and(schema_rejects(fn () => $care($another(), ['access_until' => '2026-12-01 02:00:00+00'])))->toBe('23514') // must end after it starts
        ->and(schema_rejects(fn () => $care($another(), ['status' => 'REVOKED'])))->toBe('23514') // revoked needs revoked_at
        ->and(schema_rejects(fn () => $care($another(), ['revoked_at' => '2026-12-01 03:00:00+00'])))->toBe('23514') // revoked_at while ACTIVE
        ->and(schema_rejects(fn () => $care($another(), ['status' => 'REVOKED', 'revoked_at' => '2026-12-01 03:00:00+00'])))->toBeNull()
        ->and(schema_rejects(fn () => $care($another(), ['source_type' => 'MESSAGE'])))->toBe('23514')
        // The source must exist and be about this patient and this doctor.
        ->and(schema_rejects(fn () => $care((string) Str::uuid7())))->toBe('23514')
        ->and(schema_rejects(fn () => $care($another($this->patient2))))->toBe('23514') // someone else's visit
        ->and(schema_rejects(fn () => $care($another(), ['doctor_profile_id' => $this->doctor2->id])))->toBe('23514')
        ->and(schema_rejects(fn () => $care($appointment, ['source_type' => 'APPOINTMENT'])))->toBeNull()
        ->and(schema_rejects(fn () => $care($appointment, ['source_type' => 'APPOINTMENT', 'patient_profile_id' => $this->patient2])))->toBe('23514')
        ->and(schema_rejects(fn () => $care($entry, ['source_type' => 'APPOINTMENT'])))->toBe('23514'); // an entry id is not an appointment id
});

it('binds a display device to one location by a unique token hash', function () {
    $device = fn (string $hash) => DB::table('queue_display_devices')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->location->id, 'name' => 'Lobby', 'token_hash' => $hash, 'registered_by' => $this->user]);

    expect(schema_rejects(fn () => $device(hash('sha256', 'a'))))->toBeNull()
        ->and(schema_rejects(fn () => $device(hash('sha256', 'a'))))->toBe('23505');
});

it('encrypts guest name and phone and keeps them out of serialised output', function () {
    $queue = ($this->queue)();
    $entry = (new WalkInQueueEntry)->forceFill([
        'walkin_queue_id' => $queue, 'queue_number' => 1, 'guest_name_encrypted' => 'Juan Dela Cruz', 'guest_phone_encrypted' => '+639171234567',
        'guest_phone_hash' => hash('sha256', '+639171234567'), 'registered_by_user_id' => $this->user, 'registration_channel' => 'STAFF_CONSOLE', 'status' => 'WAITING', 'payer_type' => 'SELF_PAY',
    ]);
    $entry->save();

    $raw = (array) DB::table('walkin_queue_entries')->where('id', $entry->id)->first();
    expect($raw['guest_name_encrypted'])->not->toContain('Juan')
        ->and($raw['guest_phone_encrypted'])->not->toContain('9171234567')
        ->and(WalkInQueueEntry::findOrFail($entry->id)->guest_name_encrypted)->toBe('Juan Dela Cruz')
        ->and(WalkInQueueEntry::findOrFail($entry->id)->isGuest())->toBeTrue()
        ->and(json_encode($entry->refresh()->toArray()))->not->toContain('Juan')->not->toContain('9171234567')->not->toContain($raw['guest_phone_hash']);
});

it('never returns location evidence from the presence session model', function () {
    $session = ($this->session)(['location_evidence' => json_encode(['lat' => 14.5])]);

    expect(array_keys(DoctorPresenceSession::findOrFail($session)->toArray()))->not->toContain('location_evidence');
});

it('keeps the enums equal to the database constraints, both directions', function () {
    $constraint = fn (string $name) => (string) DB::selectOne('SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?', [$name])->def;
    $index = fn (string $name) => (string) DB::selectOne('SELECT indexdef AS def FROM pg_indexes WHERE indexname = ?', [$name])->def;
    $mentions = fn (string $def, string $value) => str_contains($def, "'{$value}'");
    $values = fn (array $cases): array => collect($cases)->map(fn ($c) => $c->value)->sort()->values()->all();
    /** The IN-list of one column in one CHECK, as a sorted list of its values. */
    $inList = function (string $name, string $column) use ($constraint): array {
        preg_match('/\('.$column.'\)::text = ANY \(\(ARRAY\[(.*?)\]\)::text\[\]\)/s', $constraint($name), $list);
        expect($list)->not->toBeEmpty("no IN-list for {$name}.{$column}");
        preg_match_all("/'([A-Z_]+)'/", $list[1], $found);

        return collect($found[1])->sort()->values()->all();
    };

    // The live sets are the exact predicates of the overlap constraint and the one-live-entry index.
    $overlap = $constraint('appointments_doctor_no_overlap');
    foreach (AppointmentStatus::cases() as $status) {
        expect($mentions($overlap, $status->value))->toBe($status->isLive(), $status->value);
    }
    expect(AppointmentStatus::live())->toHaveCount(6);

    $liveEntry = $index('uq_one_live_entry_per_patient');
    foreach (EntryStatus::cases() as $status) {
        expect($mentions($liveEntry, $status->value))->toBe($status->isLive(), $status->value);
    }

    // Each enum is exactly the CHECK list of its column: nothing missing, nothing extra.
    $stored = array_filter(PresenceStatus::cases(), fn ($s) => $s->isStored());
    expect($inList('appointments_values_check', 'status'))->toBe($values(AppointmentStatus::cases()))
        ->and($inList('walkin_queue_entries_values_check', 'status'))->toBe($values(EntryStatus::cases()))
        ->and($inList('walkin_queue_events_values_check', 'from_status'))->toBe($values(EntryStatus::cases()))
        ->and($inList('walkin_queue_events_values_check', 'to_status'))->toBe($values(EntryStatus::cases()))
        ->and($inList('walkin_queues_values_check', 'status'))->toBe($values(QueueStatus::cases()))
        ->and($inList('consultations_values_check', 'status'))->toBe($values(ConsultationStatus::cases()))
        ->and($inList('doctor_presence_sessions_values_check', 'current_status'))->toBe($values($stored))
        ->and($inList('doctor_presence_events_values_check', 'new_status'))->toBe($values($stored))
        // previous_status also allows NOT_CHECKED_IN, and never BUSY.
        ->and($inList('doctor_presence_events_values_check', 'previous_status'))->toBe($values([...$stored, PresenceStatus::NotCheckedIn]))
        ->and(PresenceStatus::Busy->isStored())->toBeFalse();
});
