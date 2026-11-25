<?php

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\DTOs\RegistrationCommand;
use App\Modules\WalkIns\Enums\PayerType;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Exceptions\CapacityOverrideRequired;
use App\Modules\WalkIns\Exceptions\ConcurrentRegistration;
use App\Modules\WalkIns\Exceptions\DailyLimitReached;
use App\Modules\WalkIns\Exceptions\EntryAlreadyLive;
use App\Modules\WalkIns\Exceptions\IdempotencyKeyConflict;
use App\Modules\WalkIns\Exceptions\QueueFull;
use App\Modules\WalkIns\Exceptions\RegistrationRefused;
use App\Modules\WalkIns\Services\WalkInRegistration;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Concurrency scenario 2 (CLAUDE.md §28.4, plan §6) for walk-in registration, increment 5.5a. Real parallel processes: each racer is a
 * forked child with its own database session, released together, running the application's own registration service on committed
 * data. The assertions are invariants that hold under any interleaving: consecutive numbers, one place left means one winner,
 * one live entry per patient, one entry per key, no admission after a check-out, and the daily limits at their boundary.
 * The check-out race holds the doctor's session row from a second session (as 5.8's check-out will) while a registration waits.
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
    $this->service = DoctorService::findOrFail($this->w['service']);
    $this->staffUser = clinic_member($this->w['clinic'], Role::ClinicStaff)[0];
    $this->patients = collect(range(1, 3))->map(function () {
        $user = patient_account();
        DB::table('users')->where('id', $user->id)->update(['mobile_e164' => '+639'.random_int(100000000, 999999999), 'mobile_verified_at' => now()]);

        return ['user' => $user->refresh(), 'profile' => PatientProfile::where('user_id', $user->id)->firstOrFail()];
    });
    app('auth')->forgetGuards();
});

afterEach(function () {
    DB::purge('pgsql_second');
    fresh_test_database();
});

function wi_guest_command(User $staff, string $service, string $name, ?string $key = null, bool $override = false): RegistrationCommand
{
    return new RegistrationCommand($staff, RegistrationChannel::StaffConsole, DoctorService::findOrFail($service), $key ?? (string) Str::uuid7(), PayerType::SelfPay, null, $name, null, null,
        PriorityLevel::Normal, null, null, 'v1', $override);
}

function wi_patient_command(User $user, PatientProfile $profile, string $service, ?string $key = null): RegistrationCommand
{
    return new RegistrationCommand($user, RegistrationChannel::PatientApp, DoctorService::findOrFail($service), $key ?? (string) Str::uuid7(), PayerType::SelfPay, $profile);
}

/**
 * Runs each job in its own forked process (own database connection) and releases them together; returns, in order, ['ok' => result]
 * or ['error' => class, 'message' => ...]. The parent holds no application connection while forking; a child leaves by SIGKILL so
 * it never closes a socket it shares with the parent (a second session the test opened stays intact).
 *
 * @param  list<Closure(): mixed>  $jobs
 * @return list<array<string, mixed>>
 */
function wi_race(array $jobs): array
{
    expect(function_exists('pcntl_fork'))->toBeTrue();
    DB::purge('pgsql');
    DB::purge('pgsql_owner');

    $children = [];
    foreach ($jobs as $job) {
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            wi_child($job, $childSocket, $parentSocket);
        }
        fclose($childSocket);
        $children[] = [$pid, $parentSocket];
    }
    foreach ($children as [, $socket]) {
        fwrite($socket, 'g');
    }

    $results = [];
    foreach ($children as [$pid, $socket]) {
        $results[] = json_decode((string) stream_get_contents($socket), true) ?? ['error' => 'no result'];
        fclose($socket);
        pcntl_waitpid($pid, $status);
    }

    return $results;
}

/**
 * The forked child: waits for the start signal, runs the job, reports, and always ends by SIGKILL, whatever happens, so the child
 * never goes on to run the rest of the test suite and never closes a socket it shares with the parent.
 *
 * @param  resource  $childSocket
 * @param  resource  $parentSocket
 */
function wi_child(Closure $job, $childSocket, $parentSocket): never
{
    try {
        fclose($parentSocket);
        fread($childSocket, 1);
        $started = microtime(true);
        try {
            $out = ['ok' => $job()];
        } catch (Throwable $e) {
            $out = ['error' => $e::class, 'message' => $e->getMessage()];
        }
        $out['elapsed'] = microtime(true) - $started;
        @fwrite($childSocket, (string) json_encode($out));
    } catch (Throwable) {
        // nothing to report to; the parent sees "no result"
    } finally {
        posix_kill(posix_getpid(), SIGKILL);
    }
    exit(0);
}

/** @param list<array<string, mixed>> $results */
function wi_outcomes(array $results): array
{
    return array_map(fn ($r) => $r['error'] ?? 'ok', $results);
}

it('race 1: simultaneous staff registrations get distinct, consecutive numbers under one lazily created queue', function () {
    $jobs = collect(range(1, 4))->map(fn ($i) => fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], "Guest Number{$i}"))->entry->queue_number)->all();

    $results = wi_race($jobs);

    expect(wi_outcomes($results))->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and(collect($results)->pluck('ok')->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and(DB::table('walkin_queues')->count())->toBe(1)->and(DB::table('walkin_queues')->value('last_number'))->toBe(4)
        ->and(DB::table('walkin_queue_entries')->orderBy('queue_number')->pluck('queue_number')->all())->toBe([1, 2, 3, 4])
        ->and(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_OPENED')->count())->toBe(1);
});

it('race 2: one place left, two patients at once, exactly one succeeds and the other gets QUEUE_FULL', function () {
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 1]);
    [$a, $b] = [$this->patients[0], $this->patients[1]];

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($a['user'], $a['profile'], $this->w['service']))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($b['user'], $b['profile'], $this->w['service']))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing(['ok', QueueFull::class])->and(DB::table('walkin_queue_entries')->count())->toBe(1);
});

it('race 2b: one place left, two staff at once, exactly one succeeds and the other must confirm an override', function () {
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 1]);

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'Guest Alpha'))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'Guest Bravo'))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing(['ok', CapacityOverrideRequired::class])->and(DB::table('walkin_queue_entries')->count())->toBe(1);
});

it('race 3: the same patient twice at once makes one entry; the other gets ENTRY_ALREADY_LIVE', function () {
    $p = $this->patients[0];

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service']))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service']))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing([EntryAlreadyLive::class, 'ok'])
        ->and(DB::table('walkin_queue_entries')->count())->toBe(1)->and(DB::table('walkin_queues')->value('last_number'))->toBe(1);
});

it('race 4: the same Idempotency-Key at once makes one entry and both callers get it', function () {
    $p = $this->patients[0];
    $key = (string) Str::uuid7();

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service'], $key))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service'], $key))->entry->id,
    ]);
    $staffKey = (string) Str::uuid7();
    $staff = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'Guest Alpha', $staffKey))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'Guest Alpha', $staffKey))->entry->id,
    ]);

    expect(wi_outcomes($results))->toBe(['ok', 'ok'])->and($results[0]['ok'])->toBe($results[1]['ok'])
        ->and(wi_outcomes($staff))->toBe(['ok', 'ok'])->and($staff[0]['ok'])->toBe($staff[1]['ok'])
        ->and(DB::table('walkin_queue_entries')->count())->toBe(2)->and(DB::table('walkin_queue_entries')->where('idempotency_key', $key)->count())->toBe(1);
});

it('returns the existing entry for a key after its idempotency row is gone, comparing the body first', function () {
    $p = $this->patients[0];
    $key = (string) Str::uuid7();
    $first = app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service'], $key));

    DB::table('idempotency_keys')->delete();
    $again = app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service'], $key));
    expect($again->entry->id)->toBe($first->entry->id)->and($again->replayed)->toBeTrue()->and(DB::table('walkin_queue_entries')->count())->toBe(1);

    expect(fn () => app(WalkInRegistration::class)->register(wi_patient_command($this->patients[1]['user'], $this->patients[1]['profile'], $this->w['service'], $key)))->not->toThrow(IdempotencyKeyConflict::class);
    expect(fn () => app(WalkInRegistration::class)->register(wi_guest_command($p['user'], $this->w['service'], 'Somebody Else', $key)))->toThrow(IdempotencyKeyConflict::class);
});

it('race 5: a registration waits for a check-out in progress and then refuses the doctor who left; no queue is created', function () {
    // The competing session holds the doctor\'s open session row, as the check-out (5.8) will: session FOR UPDATE, then queues.
    config(['walkins.lock_timeout_ms' => 5000]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();
    $p = $this->patients[0];
    $outAt = $this->clock->now()->addMinute();

    $children = wi_race_with_parent(
        [fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service']))->entry->id],
        function () use ($second, $outAt) {
            usleep(1_000_000);
            $second->table('doctor_presence_sessions')->whereNull('checked_out_at')->update(['current_status' => 'OUT', 'checked_out_at' => $outAt, 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);
            $second->commit();
        },
    );

    expect($children[0]['error'] ?? null)->toBe(RegistrationRefused::class)->and($children[0]['elapsed'])->toBeGreaterThan(0.4)
        ->and(DB::table('walkin_queues')->count())->toBe(0)->and(DB::table('walkin_queue_entries')->count())->toBe(0);
});

it('race 5b: a registration that waited for a competing session which changed nothing is admitted', function () {
    config(['walkins.lock_timeout_ms' => 5000]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();
    $p = $this->patients[0];

    $children = wi_race_with_parent(
        [fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service']))->entry->id],
        function () use ($second) {
            usleep(800_000);
            $second->rollBack();
        },
    );

    expect(wi_outcomes($children))->toBe(['ok'])->and($children[0]['elapsed'])->toBeGreaterThan(0.3)->and(DB::table('walkin_queue_entries')->count())->toBe(1);
});

it('race 5c: past the lock wait the registration answers 409 CONCURRENT_MODIFICATION and leaves nothing behind', function () {
    config(['walkins.lock_timeout_ms' => 300]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();
    $p = $this->patients[0];

    expect(fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service'])))->toThrow(ConcurrentRegistration::class);
    $second->rollBack();

    expect(DB::table('walkin_queues')->count())->toBe(0)->and(DB::table('walkin_queue_entries')->count())->toBe(0);
    // The wait setting does not leak out of the registration: the next one is admitted.
    app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service']));
    expect(DB::table('walkin_queue_entries')->count())->toBe(1);
});

/** A second clinic with its own doctor, checked in, whose queue shares no row lock with the first: only the advisory locks order registrations across the two. */
function wi_second_queue(): array
{
    $w2 = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111', 'remote' => true]);
    doctor_in($w2);
    app('auth')->forgetGuards();

    return $w2;
}

it('race 6: one account registering two patients in two queues at once cannot pass the daily limit (the account lock)', function () {
    config(['walkins.remote.max_patients_per_account_per_day' => 1]);
    $w2 = wi_second_queue();
    $guardian = $this->patients[0];
    $dependant = PatientProfile::findOrFail(wi_dependent(session_for($guardian['user'])));
    app('auth')->forgetGuards();

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($guardian['user'], $guardian['profile'], $this->w['service']))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($guardian['user'], $dependant, $w2['service']))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing([DailyLimitReached::class, 'ok'])
        ->and(DB::table('walkin_queue_entries')->where('registered_by_user_id', $guardian['user']->id)->count())->toBe(1)
        ->and(DB::table('walkin_queues')->count())->toBe(1);
});

it('race 6b: one patient registering in two queues at once cannot pass the live-entry limit (the patient lock)', function () {
    config(['walkins.remote.max_live_entries_per_patient' => 1]);
    $w2 = wi_second_queue();
    $p = $this->patients[0];

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $this->w['service']))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($p['user'], $p['profile'], $w2['service']))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing([DailyLimitReached::class, 'ok'])
        ->and(DB::table('walkin_queue_entries')->where('patient_profile_id', $p['profile']->id)->count())->toBe(1);
});

it('race 1b: simultaneous staff registrations on an EXISTING queue still number consecutively (the queue row lock)', function () {
    app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'First Guest'));
    $jobs = collect(range(1, 4))->map(fn ($i) => fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], "Guest Number{$i}"))->entry->queue_number)->all();

    $results = wi_race($jobs);

    expect(wi_outcomes($results))->toBe(['ok', 'ok', 'ok', 'ok'])->and(collect($results)->pluck('ok')->sort()->values()->all())->toBe([2, 3, 4, 5])
        ->and(DB::table('walkin_queue_entries')->orderBy('queue_number')->pluck('queue_number')->all())->toBe([1, 2, 3, 4, 5]);
});

it('race 2c: one place left on an EXISTING queue, two patients at once, exactly one succeeds (the queue row lock)', function () {
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 2]);
    app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'First Guest'));
    [$a, $b] = [$this->patients[0], $this->patients[1]];

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($a['user'], $a['profile'], $this->w['service']))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($b['user'], $b['profile'], $this->w['service']))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing(['ok', QueueFull::class])->and(DB::table('walkin_queue_entries')->count())->toBe(2);
});

it('race 7: two doctors\' queues created at once at one location get distinct prefixes and no error (the location and day lock)', function () {
    $second = wi_second_doctor($this->w);

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $this->w['service'], 'Guest Alpha'))->queue->number_prefix,
        fn () => app(WalkInRegistration::class)->register(wi_guest_command($this->staffUser, $second['service'], 'Guest Bravo'))->queue->number_prefix,
    ]);

    expect(wi_outcomes($results))->toBe(['ok', 'ok'])->and(collect($results)->pluck('ok')->sort()->values()->all())->toBe(['A', 'B'])->and(DB::table('walkin_queues')->count())->toBe(2);
});

it('race 6c: a patient and an authorized guardian registering the same profile in two queues at once cannot pass the live limit (the patient lock)', function () {
    config(['walkins.remote.max_live_entries_per_patient' => 1]);
    $w2 = wi_second_queue();
    $guardian = $this->patients[0];
    $dependant = PatientProfile::findOrFail(wi_dependent(session_for($guardian['user'])));
    $own = account(Role::Patient); // the dependant has an account of their own too
    DB::table('users')->where('id', $own->id)->update(['mobile_e164' => '+639'.random_int(100000000, 999999999), 'mobile_verified_at' => now()]);
    DB::table('patient_profiles')->where('id', $dependant->id)->update(['user_id' => $own->id]);
    $patient = ['user' => $own->refresh()];
    app('auth')->forgetGuards();

    $results = wi_race([
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($guardian['user'], $dependant, $this->w['service']))->entry->id,
        fn () => app(WalkInRegistration::class)->register(wi_patient_command($patient['user'], $dependant, $w2['service']))->entry->id,
    ]);

    expect(collect(wi_outcomes($results))->sort()->values()->all())->toEqualCanonicalizing([DailyLimitReached::class, 'ok'])
        ->and(DB::table('walkin_queue_entries')->where('patient_profile_id', $dependant->id)->count())->toBe(1);
});

it('keeps both changes when a policy PATCH waits for another change to the same assignment (the row lock)', function () {
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->lockForUpdate()->first();
    $manager = $this->w['manager'];
    $url = "/api/v1/clinics/{$this->w['clinic']}/doctor-assignments/{$this->w['assignment']}/walkin-policy";

    $children = wi_race_with_parent(
        [function () use ($manager, $url) {
            app('auth')->forgetGuards();

            return $this->patchJson($url, ['allow_patient_app_registration' => false], $manager)->getStatusCode();
        }],
        function () use ($second) {
            usleep(800_000);
            $second->table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->update(['walkin_policy' => json_encode(['allow_patient_app_registration' => true, 'cutoff_minutes_before_close' => 10, 'number_prefix' => null, 'capacity_mode' => 'DERIVED'])]);
            $second->commit();
        },
    );

    $stored = json_decode((string) DB::table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->value('walkin_policy'), true);
    expect($children[0]['ok'] ?? null)->toBe(200)->and($children[0]['elapsed'])->toBeGreaterThan(0.4)
        ->and($stored['cutoff_minutes_before_close'])->toBe(10)->and($stored['allow_patient_app_registration'])->toBeFalse();
});

it('does not replay a CONCURRENT_MODIFICATION: the same key succeeds on retry', function () {
    config(['walkins.lock_timeout_ms' => 300]);
    [$user, , $headers] = [$this->patients[0]['user'], null, session_for($this->patients[0]['user'])];
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->whereNull('checked_out_at')->lockForUpdate()->first();
    $key = (string) Str::uuid7();
    $body = patient_body($this->w['service'], (string) $this->patients[0]['profile']->id);

    register_walkin($headers, $body, $key)->assertStatus(409)->assertJsonPath('code', 'CONCURRENT_MODIFICATION');
    expect(DB::table('idempotency_keys')->where('key', $key)->count())->toBe(0);
    $second->rollBack();

    register_walkin($headers, $body, $key)->assertCreated();
    expect(DB::table('walkin_queue_entries')->count())->toBe(1);
});

/**
 * Forks the racers, runs $parent while they work (the parent keeps its second session, which the children inherit but never touch),
 * and returns the children's results.
 *
 * @param  list<Closure(): mixed>  $jobs
 * @return list<array<string, mixed>>
 */
function wi_race_with_parent(array $jobs, Closure $parent): array
{
    DB::purge('pgsql');
    DB::purge('pgsql_owner');

    $children = [];
    foreach ($jobs as $job) {
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            wi_child($job, $childSocket, $parentSocket);
        }
        fclose($childSocket);
        $children[] = [$pid, $parentSocket];
    }
    foreach ($children as [, $socket]) {
        fwrite($socket, 'g');
    }
    $parent();

    $results = [];
    foreach ($children as [$pid, $socket]) {
        $results[] = json_decode((string) stream_get_contents($socket), true) ?? ['error' => 'no result'];
        fclose($socket);
        pcntl_waitpid($pid, $status);
    }

    return $results;
}
