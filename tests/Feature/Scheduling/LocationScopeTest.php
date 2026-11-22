<?php

use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SchedulingPolicySeeder;

/*
 * Increment 5.2 (phase 5 plan Q14, approved behaviour change): clinic membership
 * narrowed to one location (`clinic_members.clinic_location_id`) now limits the Phase 4
 * schedule, exception and service-mode endpoints to that location. Staff restricted to
 * location A lose access to location B; whole-clinic members (null location) are unchanged.
 */

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinicId, $this->locA, $this->manager] = clinic_with_manager();
    $this->locB = add_location($this->clinicId, $this->manager, 'Branch B');
    [$this->doctor, $this->doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    assign($this->clinicId, $this->locA, $this->manager, $this->doctorHeaders);
    assign($this->clinicId, $this->locB, $this->manager, $this->doctorHeaders);

    foreach (['A' => $this->locA, 'B' => $this->locB] as $key => $location) {
        $service = $this->postJson('/api/v1/doctors/me/services', in_person($location, 65000, "Consultation {$key}"), $this->doctorHeaders)->assertCreated()->json('data.id');
        $mode = $this->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'BOTH'], $this->doctorHeaders)->assertOk()->json('data.service_mode_id');
        $this->{"service{$key}"} = $service;
        $this->{"mode{$key}"} = $mode;
        $this->{"schedule{$key}"} = $this->postJson('/api/v1/schedules', location_schedule($mode, $key === 'A' ? 1 : 2), $this->doctorHeaders)->assertCreated()->json('data.id');
        $this->{"closure{$key}"} = $this->postJson('/api/v1/schedule-exceptions', location_closure($this->doctor->id, $location), $this->manager)->assertCreated()->json('data.id');
        app('auth')->forgetGuards();
    }

    [, $this->restrictedManager] = clinic_member($this->clinicId, Role::ClinicManager, $this->locA);
    [, $this->restrictedStaff] = clinic_member($this->clinicId, Role::ClinicStaff, $this->locA);
    app('auth')->forgetGuards();
});

function location_schedule(string $modeId, int $day = 1): array
{
    return ['name' => 'Weekday mornings', 'effective_from' => '2026-12-01', 'status' => 'ACTIVE', 'shifts' => [
        ['day_of_week' => $day, 'doctor_service_mode_id' => $modeId, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5, 'max_appointments' => 6, 'walkin_capacity' => 4],
    ]];
}

function location_closure(string $doctorId, string $locationId): array
{
    return ['doctor_profile_id' => $doctorId, 'exception_type' => 'CLINIC_CLOSURE', 'clinic_location_id' => $locationId, 'reason' => 'Renovation',
        'starts_at' => now()->addDays(10)->startOfDay()->toIso8601String(), 'ends_at' => now()->addDays(12)->startOfDay()->toIso8601String()];
}

/** Calls the API as another member: the test client caches the signed-in user on the guard. */
function as_member(array $headers): array
{
    app('auth')->forgetGuards();

    return $headers;
}

it('shows a location-restricted manager only their location\'s schedules', function () {
    $ids = collect($this->getJson("/api/v1/schedules?clinic_id={$this->clinicId}", as_member($this->restrictedManager))->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->scheduleA]);

    $this->getJson("/api/v1/schedules/{$this->scheduleA}", as_member($this->restrictedManager))->assertOk();
    $this->getJson("/api/v1/schedules/{$this->scheduleB}", as_member($this->restrictedManager))->assertForbidden();

    // Whole-clinic members are unchanged.
    $all = collect($this->getJson("/api/v1/schedules?clinic_id={$this->clinicId}", as_member($this->manager))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($all)->toBe(collect([$this->scheduleA, $this->scheduleB])->sort()->values()->all());
});

it('refuses a location-restricted manager changing another location\'s schedules', function () {
    $this->postJson('/api/v1/schedules', [...location_schedule($this->modeB, 3), 'doctor_profile_id' => $this->doctor->id], as_member($this->restrictedManager))->assertForbidden();
    $this->patchJson("/api/v1/schedules/{$this->scheduleB}", ['name' => 'Taken over'], [...as_member($this->restrictedManager), 'If-Match' => '1'])->assertForbidden();
    $this->deleteJson("/api/v1/schedules/{$this->scheduleB}", [], [...as_member($this->restrictedManager), 'If-Match' => '1'])->assertForbidden();

    // At their own location they can.
    $this->postJson('/api/v1/schedules', [...location_schedule($this->modeA, 3), 'name' => 'Extra', 'doctor_profile_id' => $this->doctor->id], as_member($this->restrictedManager))->assertCreated();
    $this->patchJson("/api/v1/schedules/{$this->scheduleA}", ['name' => 'Renamed'], [...as_member($this->restrictedManager), 'If-Match' => '1'])->assertOk();
    $this->deleteJson("/api/v1/schedules/{$this->scheduleA}", [], [...as_member($this->restrictedManager), 'If-Match' => '2'])->assertOk();

    // B is untouched.
    expect(DB::table('doctor_schedules')->where('id', $this->scheduleB)->value('status'))->toBe('ACTIVE');
});

it('keeps location-restricted staff to read-only access at their own location', function () {
    $ids = collect($this->getJson("/api/v1/schedules?clinic_id={$this->clinicId}", as_member($this->restrictedStaff))->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->scheduleA]);

    $this->getJson("/api/v1/schedules/{$this->scheduleB}", as_member($this->restrictedStaff))->assertForbidden();
    $this->patchJson("/api/v1/schedules/{$this->scheduleA}", ['name' => 'Staff edit'], [...as_member($this->restrictedStaff), 'If-Match' => '1'])->assertForbidden();
});

it('scopes exceptions to the location for restricted members', function () {
    $listed = collect($this->getJson("/api/v1/schedule-exceptions?clinic_id={$this->clinicId}", as_member($this->restrictedManager))->assertOk()->json('data'))->pluck('id')->all();
    expect($listed)->toBe([$this->closureA]);

    $this->postJson('/api/v1/schedule-exceptions', location_closure($this->doctor->id, $this->locB), as_member($this->restrictedManager))->assertForbidden();
    $this->deleteJson("/api/v1/schedule-exceptions/{$this->closureB}", [], as_member($this->restrictedManager))->assertForbidden();

    $this->postJson('/api/v1/schedule-exceptions', location_closure($this->doctor->id, $this->locA), as_member($this->restrictedManager))->assertCreated();
    $this->deleteJson("/api/v1/schedule-exceptions/{$this->closureA}", [], as_member($this->restrictedManager))->assertOk();

    expect(DB::table('doctor_schedule_exceptions')->where('id', $this->closureB)->value('status'))->toBe('ACTIVE');
});

it('scopes service modes to the location for restricted members', function () {
    $listed = collect($this->getJson("/api/v1/clinics/{$this->clinicId}/service-modes", as_member($this->restrictedManager))->assertOk()->json('data'))->pluck('clinic_location_id')->unique()->all();
    expect($listed)->toBe([$this->locA]);

    $this->putJson("/api/v1/clinics/{$this->clinicId}/services/{$this->serviceB}/mode", ['service_mode' => 'APPOINTMENT'], as_member($this->restrictedManager))->assertForbidden();
    $this->putJson("/api/v1/clinics/{$this->clinicId}/services/{$this->serviceA}/mode", ['service_mode' => 'APPOINTMENT'], as_member($this->restrictedManager))->assertOk()->assertJsonPath('data.service_mode', 'APPOINTMENT');

    // A whole-clinic manager sees both, as before.
    $both = collect($this->getJson("/api/v1/clinics/{$this->clinicId}/service-modes", as_member($this->manager))->assertOk()->json('data'))->pluck('clinic_location_id')->unique()->sort()->values()->all();
    expect($both)->toBe(collect([$this->locA, $this->locB])->sort()->values()->all());
});

it('stops a restricted manager moving their location\'s schedule to another location', function () {
    // Editing the shifts re-checks the new location: the shifts' service mode belongs to B.
    $moveToB = ['shifts' => location_schedule($this->modeB, 4)['shifts']];

    $this->patchJson("/api/v1/schedules/{$this->scheduleA}", $moveToB, [...as_member($this->restrictedManager), 'If-Match' => '1'])->assertForbidden();

    expect(DB::table('doctor_schedule_shifts')->where('doctor_schedule_id', $this->scheduleA)->pluck('clinic_location_id')->unique()->all())->toBe([$this->locA]);
});

it('lets the doctor keep managing their own schedules at every location', function () {
    $this->getJson('/api/v1/schedules', as_member($this->doctorHeaders))->assertOk()->assertJsonCount(2, 'data');
    $this->patchJson("/api/v1/schedules/{$this->scheduleB}", ['name' => 'Mine'], [...as_member($this->doctorHeaders), 'If-Match' => '1'])->assertOk();
});
