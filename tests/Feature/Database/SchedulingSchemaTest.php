<?php

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 4.1: the scheduling and appointment tables enforce the booking
 * guarantees themselves (CLAUDE.md §7.5, architecture.md §8.3 layers 4 and 5).
 * Raw inserts on purpose: these must hold even if every service-layer check
 * has a bug.
 */

/** Runs $write in a savepoint and returns the SQLSTATE it failed with (null when it succeeded). */
function db_rejects(callable $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return (string) $e->getCode();
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
    $services = DoctorService::where('doctor_profile_id', $this->doctor->id)->get();

    $mode = function (?string $location, string $type, string $serviceId, string $mode = 'APPOINTMENT'): string {
        $id = (string) Str::uuid7();
        DB::table('doctor_service_modes')->insert(['id' => $id, 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $location, 'doctor_service_id' => $serviceId, 'consultation_type' => $type, 'service_mode' => $mode, 'effective_from' => '2026-11-01']);

        return $id;
    };
    $this->inPersonService = (string) $services->firstWhere('consultation_type.value', 'IN_PERSON')?->id;
    $this->onlineService = (string) $services->firstWhere('consultation_type.value', 'ONLINE')?->id;
    $this->inPersonMode = $mode($this->location->id, 'IN_PERSON', $this->inPersonService, 'BOTH');
    $this->onlineMode = $mode(null, 'ONLINE', $this->onlineService);
    $this->mode = $mode;

    $this->slot = function (string $modeId, ?string $location, string $start, string $end): string {
        $id = (string) Str::uuid7();
        DB::table('doctor_availability_slots')->insert(['id' => $id, 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $location, 'doctor_service_mode_id' => $modeId, 'slot_kind' => 'APPOINTMENT', 'starts_at' => $start, 'ends_at' => $end, 'local_date' => substr($start, 0, 10), 'generation' => 1]);

        return $id;
    };
    $this->hold = fn (string $slotId, string $start, string $end, string $status = 'ACTIVE') => DB::table('appointment_slot_reservations')->insert([
        'id' => (string) Str::uuid7(), 'doctor_availability_slot_id' => $slotId, 'doctor_profile_id' => $this->doctor->id, 'starts_at' => $start, 'ends_at' => $end,
        'patient_profile_id' => $this->patient, 'booked_by_user_id' => $this->user, 'status' => $status, 'expires_at' => now()->addMinutes(10),
    ]);
    $this->appointment = fn (?string $location, string $type, string $start, string $end, string $status = 'CONFIRMED', array $extra = []) => DB::table('appointments')->insert([
        'id' => (string) Str::uuid7(), 'reference_code' => strtoupper(Str::random(8)), 'patient_profile_id' => $this->patient, 'booked_by_user_id' => $this->user,
        'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $location, 'doctor_service_id' => $type === 'ONLINE' ? $this->onlineService : $this->inPersonService,
        'doctor_service_mode_id' => $type === 'ONLINE' ? $this->onlineMode : $this->inPersonMode, 'consultation_type' => $type, 'starts_at' => $start, 'ends_at' => $end,
        'timezone' => 'Asia/Manila', 'status' => $status, 'payer_type' => 'SELF_PAY', 'fee_amount' => 65000, ...$extra,
    ]);
});

it('allows only one active hold per slot; a finished hold frees it', function () {
    $slot = ($this->slot)($this->inPersonMode, $this->location->id, '2026-12-01 01:00:00+00', '2026-12-01 01:20:00+00');
    ($this->hold)($slot, '2026-12-01 01:00:00+00', '2026-12-01 01:20:00+00');

    expect(db_rejects(fn () => ($this->hold)($slot, '2026-12-01 01:00:00+00', '2026-12-01 01:20:00+00')))->toBe('23505'); // unique index

    DB::table('appointment_slot_reservations')->update(['status' => 'EXPIRED']);
    expect(db_rejects(fn () => ($this->hold)($slot, '2026-12-01 01:00:00+00', '2026-12-01 01:20:00+00')))->toBeNull();
});

it('rejects overlapping active holds on two different slots of one doctor (scenario 11 at the database layer)', function () {
    $inPerson = ($this->slot)($this->inPersonMode, $this->location->id, '2026-12-01 01:00:00+00', '2026-12-01 01:20:00+00');
    $online = ($this->slot)($this->onlineMode, null, '2026-12-01 01:10:00+00', '2026-12-01 01:25:00+00');
    ($this->hold)($inPerson, '2026-12-01 01:00:00+00', '2026-12-01 01:20:00+00');

    expect(db_rejects(fn () => ($this->hold)($online, '2026-12-01 01:10:00+00', '2026-12-01 01:25:00+00')))->toBe('23P01'); // exclusion constraint

    // Back to back is not an overlap: ranges are [start, end).
    $next = ($this->slot)($this->onlineMode, null, '2026-12-01 01:20:00+00', '2026-12-01 01:35:00+00');
    expect(db_rejects(fn () => ($this->hold)($next, '2026-12-01 01:20:00+00', '2026-12-01 01:35:00+00')))->toBeNull();
});

it('rejects overlapping live appointments across a location and online, but not cancelled ones', function () {
    ($this->appointment)($this->location->id, 'IN_PERSON', '2026-12-02 01:00:00+00', '2026-12-02 01:20:00+00');

    expect(db_rejects(fn () => ($this->appointment)(null, 'ONLINE', '2026-12-02 01:10:00+00', '2026-12-02 01:25:00+00')))->toBe('23P01')
        ->and(db_rejects(fn () => ($this->appointment)(null, 'ONLINE', '2026-12-02 01:10:00+00', '2026-12-02 01:25:00+00', 'CANCELLED')))->toBeNull()
        ->and(db_rejects(fn () => ($this->appointment)(null, 'ONLINE', '2026-12-02 01:20:00+00', '2026-12-02 01:35:00+00')))->toBeNull();
});

it('keeps appointment status history append-only', function () {
    ($this->appointment)($this->location->id, 'IN_PERSON', '2026-12-03 01:00:00+00', '2026-12-03 01:20:00+00');
    $appointment = (string) DB::table('appointments')->value('id');
    DB::table('appointment_status_history')->insert(['appointment_id' => $appointment, 'to_status' => 'CONFIRMED', 'actor_type' => 'SYSTEM']);

    expect(db_rejects(fn () => DB::table('appointment_status_history')->update(['to_status' => 'CANCELLED'])))->not->toBeNull()
        ->and(db_rejects(fn () => DB::table('appointment_status_history')->delete()))->not->toBeNull()
        ->and(DB::table('appointment_status_history')->value('to_status'))->toBe('CONFIRMED');
});

it('enforces service mode rules: online has no location and is appointment-only', function () {
    expect(db_rejects(fn () => ($this->mode)($this->location->id, 'ONLINE', $this->onlineService)))->toBe('23514')
        ->and(db_rejects(fn () => ($this->mode)(null, 'ONLINE', $this->onlineService, 'WALK_IN')))->toBe('23514')
        ->and(db_rejects(fn () => ($this->mode)(null, 'IN_PERSON', $this->inPersonService)))->toBe('23514');
});

it('rejects overlapping shifts in one schedule on the same weekday', function () {
    $schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->doctor->id, 'name' => 'Makati weekdays', 'effective_from' => '2026-12-01', 'created_by' => $this->user]);
    $shift = fn (int $day, string $from, string $to) => DB::table('doctor_schedule_shifts')->insert([
        'id' => (string) Str::uuid7(), 'doctor_schedule_id' => $schedule, 'day_of_week' => $day, 'clinic_location_id' => $this->location->id, 'doctor_service_mode_id' => $this->inPersonMode,
        'starts_at_local' => $from, 'ends_at_local' => $to, 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20,
    ]);
    $shift(1, '09:00', '12:00');

    expect(db_rejects(fn () => $shift(1, '11:00', '14:00')))->toBe('23P01')
        ->and(db_rejects(fn () => $shift(1, '12:00', '15:00')))->toBeNull()
        ->and(db_rejects(fn () => $shift(2, '11:00', '14:00')))->toBeNull()
        ->and(db_rejects(fn () => $shift(3, '14:00', '13:00')))->toBe('23514');
});

it('lets only special hours add time (D-11)', function () {
    $exception = fn (string $type, string $effect) => DB::table('doctor_schedule_exceptions')->insert([
        'id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctor->id, 'exception_type' => $type, 'effect' => $effect, 'priority' => 2,
        'starts_at' => '2026-12-24 00:00:00+00', 'ends_at' => '2026-12-26 00:00:00+00', 'requested_by' => $this->user,
    ]);

    expect(db_rejects(fn () => $exception('SPECIAL_HOURS', 'BLOCK')))->toBe('23514')
        ->and(db_rejects(fn () => $exception('VACATION', 'ADD_HOURS')))->toBe('23514')
        ->and(db_rejects(fn () => $exception('VACATION', 'BLOCK')))->toBeNull()
        ->and(db_rejects(fn () => $exception('SPECIAL_HOURS', 'ADD_HOURS')))->toBeNull();
});

it('keeps policy scope consistent and leaves undecided values empty', function () {
    $policy = fn (string $scope, ?string $id) => DB::table('scheduling_policies')->insert(['id' => (string) Str::uuid7(), 'scope_type' => $scope, 'scope_id' => $id, 'reservation_hold_minutes' => 10, 'effective_from' => now()]);

    expect(db_rejects(fn () => $policy('PLATFORM', (string) Str::uuid7())))->toBe('23514')
        ->and(db_rejects(fn () => $policy('DOCTOR', null)))->toBe('23514')
        ->and(db_rejects(fn () => $policy('PLATFORM', null)))->toBeNull()
        ->and(DB::table('scheduling_policies')->value('free_cancellation_hours'))->toBeNull(); // D-22 not decided
});

it('requires a membership for HMO appointments and a location for in-person ones', function () {
    expect(db_rejects(fn () => ($this->appointment)($this->location->id, 'IN_PERSON', '2026-12-04 01:00:00+00', '2026-12-04 01:20:00+00', 'CONFIRMED', ['payer_type' => 'HMO'])))->toBe('23514')
        ->and(db_rejects(fn () => ($this->appointment)(null, 'IN_PERSON', '2026-12-04 02:00:00+00', '2026-12-04 02:20:00+00')))->toBe('23514');
});
