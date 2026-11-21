<?php

use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Scheduling\Services\SchedulingPolicies;
use Database\Seeders\SchedulingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 4.2: service modes per doctor, location and service (§7.1), kept
 * across fee changes (Phase 4 plan Q8), and scheduling policies resolved
 * field by field (architecture.md §8.1).
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinicId, $this->locationId, $this->manager] = clinic_with_manager();
    [$this->doctor, $this->doctorHeaders] = verified_doctor();
    assign($this->clinicId, $this->locationId, $this->manager, $this->doctorHeaders);
    $this->inPerson = $this->postJson('/api/v1/doctors/me/services', in_person($this->locationId, 65000), $this->doctorHeaders)->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $this->online = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $this->doctorHeaders)->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
});

it('lists services without a mode, then sets one per service; online is appointment-only', function () {
    $list = $this->getJson('/api/v1/doctors/me/service-modes', $this->doctorHeaders)->assertOk()->json('data');
    expect($list)->toHaveCount(2)->and(collect($list)->pluck('service_mode')->unique()->all())->toBe([null]);

    $this->putJson("/api/v1/doctors/me/services/{$this->inPerson}/mode", ['service_mode' => 'BOTH'], $this->doctorHeaders)
        ->assertOk()->assertJsonPath('data.service_mode', 'BOTH')->assertJsonPath('data.clinic_location_id', $this->locationId);
    $this->putJson("/api/v1/doctors/me/services/{$this->online}/mode", ['service_mode' => 'WALK_IN'], $this->doctorHeaders)
        ->assertStatus(422)->assertJsonPath('errors.service_mode.0', 'Online consultations are by appointment only.');
    $this->putJson("/api/v1/doctors/me/services/{$this->online}/mode", ['service_mode' => 'APPOINTMENT'], $this->doctorHeaders)->assertOk();
    $this->putJson("/api/v1/doctors/me/services/{$this->online}/mode", ['service_mode' => 'SOMETIMES'], $this->doctorHeaders)->assertStatus(422);

    expect(DB::table('doctor_service_modes')->where('status', 'ACTIVE')->count())->toBe(2)
        ->and(DB::table('outbox_events')->where('event_type', 'scheduling.inputs_changed')->where('aggregate_id', $this->doctor->id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'scheduling.service_mode_set')->count())->toBe(2);
});

it('keeps the mode when the fee changes, and drops it when the service ends', function () {
    $modeId = $this->putJson("/api/v1/doctors/me/services/{$this->inPerson}/mode", ['service_mode' => 'WALK_IN'], $this->doctorHeaders)->json('data.service_mode_id');

    $replacement = $this->patchJson("/api/v1/doctors/me/services/{$this->inPerson}", ['fee_amount' => 80000], $this->doctorHeaders)->assertOk()->json('data.id');
    expect($replacement)->not->toBe($this->inPerson);
    $row = collect($this->getJson('/api/v1/doctors/me/service-modes', $this->doctorHeaders)->json('data'))->firstWhere('doctor_service_id', $replacement);
    expect($row['service_mode'])->toBe('WALK_IN')->and($row['service_mode_id'])->toBe($modeId); // same mode row: shifts stay attached

    $this->deleteJson("/api/v1/doctors/me/services/{$replacement}", [], $this->doctorHeaders)->assertOk();
    expect(DB::table('doctor_service_modes')->where('id', $modeId)->value('status'))->toBe('INACTIVE');
});

it('drops the modes at a location when the clinic ends the assignment', function () {
    $this->putJson("/api/v1/doctors/me/services/{$this->inPerson}/mode", ['service_mode' => 'BOTH'], $this->doctorHeaders)->assertOk();
    app('auth')->forgetGuards();
    $assignment = DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $this->doctor->id)->value('id');
    $this->postJson("/api/v1/clinics/{$this->clinicId}/doctor-assignments/{$assignment}/end", ['reason' => 'Moved away'], $this->manager)->assertOk();

    expect(DB::table('doctor_service_modes')->where('doctor_service_id', $this->inPerson)->value('status'))->toBe('INACTIVE');
});

it('lets the clinic manage in-person modes at its own locations only', function () {
    $this->putJson("/api/v1/clinics/{$this->clinicId}/services/{$this->inPerson}/mode", ['service_mode' => 'APPOINTMENT'], $this->manager)->assertOk();
    $listed = $this->getJson("/api/v1/clinics/{$this->clinicId}/service-modes", $this->manager)->assertOk()->json('data');
    expect(collect($listed)->pluck('doctor_service_id')->all())->toBe([$this->inPerson]); // the online service is the doctor's own
    $this->putJson("/api/v1/clinics/{$this->clinicId}/services/{$this->online}/mode", ['service_mode' => 'APPOINTMENT'], $this->manager)->assertNotFound();
    app('auth')->forgetGuards();

    [$otherClinic, , $otherManager] = clinic_with_manager('Diliman Medical Clinic');
    $this->putJson("/api/v1/clinics/{$this->clinicId}/services/{$this->inPerson}/mode", ['service_mode' => 'BOTH'], $otherManager)->assertForbidden();
    $this->putJson("/api/v1/clinics/{$otherClinic}/services/{$this->inPerson}/mode", ['service_mode' => 'BOTH'], $otherManager)->assertNotFound();
    expect(DB::table('doctor_service_modes')->where('doctor_service_id', $this->inPerson)->value('service_mode'))->toBe('APPOINTMENT');
});

it('lets clinic staff view modes but not change them, and keeps patients out', function () {
    $staff = account();
    app(AssignsRoles::class)->assign($staff, Role::ClinicStaff);
    (new ClinicMember)->forceFill(['clinic_id' => $this->clinicId, 'user_id' => $staff->id, 'member_role' => 'CLINIC_STAFF', 'status' => 'ACTIVE', 'joined_at' => now()])->save();
    $staffHeaders = session_for($staff->refresh());

    $this->getJson("/api/v1/clinics/{$this->clinicId}/service-modes", $staffHeaders)->assertOk();
    $this->putJson("/api/v1/clinics/{$this->clinicId}/services/{$this->inPerson}/mode", ['service_mode' => 'BOTH'], $staffHeaders)->assertForbidden();
    app('auth')->forgetGuards();

    $patient = session_for(patient_account());
    $this->getJson('/api/v1/doctors/me/service-modes', $patient)->assertNotFound(); // patients may view schedules (G⁴) but have no doctor profile
    $this->putJson("/api/v1/doctors/me/services/{$this->inPerson}/mode", ['service_mode' => 'BOTH'], $patient)->assertForbidden();
});

it('resolves policies field by field, most specific first', function () {
    $policies = app(SchedulingPolicies::class);
    $platform = $policies->resolve($this->doctor->id, $this->locationId);
    expect($platform['reservation_hold_minutes'])->toBe(10)
        ->and($platform['reservation_max_extension_minutes'])->toBe(10)
        ->and($platform['confirmation_hold_hours'])->toBe(24)
        ->and($platform['availability_horizon_days'])->toBe(60)
        ->and($platform['free_cancellation_hours'])->toBeNull(); // D-22 not decided

    $insert = fn (string $scope, string $id, array $values, string $from = '2026-01-01') => DB::table('scheduling_policies')->insert(['id' => (string) Str::uuid7(), 'scope_type' => $scope, 'scope_id' => $id, 'effective_from' => $from, ...$values]);
    $insert('CLINIC_LOCATION', $this->locationId, ['reservation_hold_minutes' => 15, 'min_booking_notice_minutes' => 120]);
    $insert('DOCTOR', $this->doctor->id, ['reservation_hold_minutes' => 12]);
    $insert('DOCTOR', $this->doctor->id, ['reservation_hold_minutes' => 30], now()->addDay()->toDateString()); // not in force yet

    $resolved = $policies->resolve($this->doctor->id, $this->locationId);
    expect($resolved['reservation_hold_minutes'])->toBe(12) // doctor beats location
        ->and($resolved['min_booking_notice_minutes'])->toBe(120) // location beats platform
        ->and($resolved['max_advance_booking_days'])->toBe(60) // inherited from platform
        ->and($policies->resolve($this->doctor->id)['min_booking_notice_minutes'])->toBe(60); // online: no location policy
});

it('refuses to resolve without a platform policy', function () {
    DB::table('scheduling_policies')->delete();
    app(SchedulingPolicies::class)->resolve($this->doctor->id);
})->throws(LogicException::class, 'platform scheduling policy');
