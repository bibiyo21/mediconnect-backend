<?php

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Services\AvailabilityProjector;
use App\Modules\Scheduling\Services\SlotHolds;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SchedulingPolicySeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Concurrency scenarios 1, 11 and 4 (CLAUDE.md §28.4, Phase 4 exit tests):
 * real parallel database sessions with committed data. Session 2 plays the
 * competing patient inside an open transaction while the application's hold
 * path runs on the default connection.
 */

beforeEach(function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql'), 'scheduling.hold_lock_timeout_ms' => 300]);
    fresh_test_database();
    $this->seed(RbacSeeder::class);
    $this->seed(SchedulingPolicySeeder::class);
    $this->seed(SpecialtySeeder::class);

    $this->location = directory_clinic();
    foreach (range(1, 6) as $day) {
        DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->location->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $this->location);
    $services = DB::table('doctor_services')->where('doctor_profile_id', $this->doctor->id)->get()->keyBy('consultation_type');
    $mode = function (?string $location, string $type, string $service): string {
        $id = (string) Str::uuid7();
        DB::table('doctor_service_modes')->insert(['id' => $id, 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $location, 'doctor_service_id' => $service, 'consultation_type' => $type, 'service_mode' => 'APPOINTMENT', 'effective_from' => '2026-01-01']);

        return $id;
    };
    $this->inPersonMode = $mode($this->location->id, 'IN_PERSON', $services['IN_PERSON']->id);
    $this->onlineMode = $mode(null, 'ONLINE', $services['ONLINE']->id);
    $this->schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $this->schedule, 'doctor_profile_id' => $this->doctor->id, 'name' => 'Mondays', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doctor->user_id]);
    DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $this->schedule, 'day_of_week' => 1, 'clinic_location_id' => $this->location->id, 'doctor_service_mode_id' => $this->inPersonMode, 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]);
    $this->monday = CarbonImmutable::now('Asia/Manila')->addDays(2)->next(CarbonImmutable::MONDAY)->toDateString();
    DB::table('doctor_schedule_exceptions')->insert(['id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctor->id, 'doctor_service_mode_id' => $this->onlineMode, 'exception_type' => 'SPECIAL_HOURS', 'effect' => 'ADD_HOURS', 'priority' => 4,
        'starts_at' => $this->monday.' 09:00:00+08', 'ends_at' => $this->monday.' 10:00:00+08', 'requested_by' => $this->doctor->user_id, 'status' => 'ACTIVE']);
    app(AvailabilityProjector::class)->refresh($this->doctor->id);

    $this->slot = DB::table('doctor_availability_slots')->where('local_date', $this->monday)->where('doctor_service_mode_id', $this->inPersonMode)->orderBy('starts_at')->first();
    $this->online = DB::table('doctor_availability_slots')->where('local_date', $this->monday)->where('doctor_service_mode_id', $this->onlineMode)->orderBy('starts_at')->first();
    $this->patientA = (string) DB::table('patient_profiles')->value('id');
    $this->patientB = PatientProfile::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz'])->id;
    $this->actor = User::findOrFail($this->doctor->user_id);
});

afterEach(fn () => fresh_test_database());

/** Session 2 holds a slot for patient B inside an open transaction, as the hold path would. */
function competing_hold(object $slot, string $patient, string $actor): Connection
{
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_availability_slots')->where('id', $slot->id)->lockForUpdate()->first();
    $second->table('appointment_slot_reservations')->insert(['id' => (string) Str::uuid7(), 'doctor_availability_slot_id' => $slot->id, 'doctor_profile_id' => $slot->doctor_profile_id,
        'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'patient_profile_id' => $patient, 'booked_by_user_id' => $actor, 'status' => 'ACTIVE', 'expires_at' => now()->addMinutes(10)]);

    return $second;
}

it('scenario 1: two patients holding the same slot, exactly one succeeds', function () {
    $second = competing_hold($this->slot, $this->patientB, $this->actor->id);

    // While session 2's transaction is open, the hold waits on the slot row lock and gives up with 409.
    expect(fn () => app(SlotHolds::class)->hold($this->slot->id, $this->patientA, $this->actor))->toThrow(SlotUnavailable::class);

    // After session 2 commits, a retry sees the active hold: still 409, never a second hold.
    $second->commit();
    expect(fn () => app(SlotHolds::class)->hold($this->slot->id, $this->patientA, $this->actor))->toThrow(SlotUnavailable::class)
        ->and(DB::table('appointment_slot_reservations')->where('status', 'ACTIVE')->pluck('patient_profile_id')->all())->toBe([$this->patientB]);
});

it('scenario 11: two different slots whose times overlap, exactly one succeeds (exclusion constraint)', function () {
    expect($this->online->id)->not->toBe($this->slot->id)
        ->and($this->online->starts_at < $this->slot->ends_at && $this->slot->starts_at < $this->online->ends_at)->toBeTrue();
    $second = competing_hold($this->slot, $this->patientB, $this->actor->id);

    // A different slot row: no row lock to wait on, so the exclusion constraint is what refuses it.
    expect(fn () => app(SlotHolds::class)->hold($this->online->id, $this->patientA, $this->actor))->toThrow(SlotUnavailable::class);
    $second->commit();
    expect(fn () => app(SlotHolds::class)->hold($this->online->id, $this->patientA, $this->actor))->toThrow(SlotUnavailable::class)
        ->and(DB::table('appointment_slot_reservations')->where('status', 'ACTIVE')->count())->toBe(1);
});

it('scenario 4: a schedule change during a hold keeps the hold and flags the slot; a new hold on the removed time is refused', function () {
    $hold = app(SlotHolds::class)->hold($this->slot->id, $this->patientA, $this->actor);
    $other = DB::table('doctor_availability_slots')->where('local_date', $this->monday)->where('doctor_service_mode_id', $this->inPersonMode)->orderBy('starts_at')->skip(3)->first();

    // Session 2 archives the schedule and commits while the hold is live; the projection has not been refreshed yet.
    $second = DB::connection('pgsql_second');
    $second->transaction(fn () => $second->table('doctor_schedules')->where('id', $this->schedule)->update(['status' => 'ARCHIVED', 'version' => 2]));

    // Revalidation reads the committed schedule, so the stale projection row cannot be held.
    expect(fn () => app(SlotHolds::class)->hold($other->id, $this->patientB, $this->actor))->toThrow(SlotUnavailable::class);

    app(AvailabilityProjector::class)->refresh($this->doctor->id);
    expect(DB::table('appointment_slot_reservations')->where('id', $hold['id'])->value('status'))->toBe('ACTIVE')
        ->and(DB::table('doctor_availability_slots')->where('id', $this->slot->id)->value('status'))->toBe('NEEDS_REVIEW')
        ->and(DB::table('doctor_availability_slots')->where('id', $other->id)->exists())->toBeFalse();
});
