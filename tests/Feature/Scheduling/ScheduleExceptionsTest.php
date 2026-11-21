<?php

use App\Modules\Patients\Models\PatientProfile;
use Database\Seeders\SchedulingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 4.4: schedule exceptions with the D-11 priority. Blocking time
 * flags overlapping appointments for rescheduling and never cancels them (§8.3).
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinicId, $this->locationId, $this->manager] = clinic_with_manager();
    [$this->doctor, $this->doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    assign($this->clinicId, $this->locationId, $this->manager, $this->doctorHeaders);
    $this->service = $this->postJson('/api/v1/doctors/me/services', in_person($this->locationId, 65000), $this->doctorHeaders)->assertCreated()->json('data.id');
    $this->mode = $this->putJson("/api/v1/doctors/me/services/{$this->service}/mode", ['service_mode' => 'APPOINTMENT'], $this->doctorHeaders)->json('data.service_mode_id');
    app('auth')->forgetGuards();
});

function leave(array $overrides = []): array
{
    return ['exception_type' => 'VACATION', 'starts_at' => now()->addDays(10)->startOfDay()->toIso8601String(), 'ends_at' => now()->addDays(12)->startOfDay()->toIso8601String(), 'reason' => 'Family trip', ...$overrides];
}

it('records leave at the D-11 priority, for all places when no location is given', function () {
    $data = $this->postJson('/api/v1/schedule-exceptions', leave(), $this->doctorHeaders)->assertCreated()->json('data');

    expect($data)->toMatchArray(['exception_type' => 'VACATION', 'effect' => 'BLOCK', 'priority' => 2, 'clinic_location_id' => null, 'status' => 'ACTIVE', 'affected_appointments' => []])
        ->and(DB::table('outbox_events')->where('event_type', 'scheduling.inputs_changed')->exists())->toBeTrue();
    // The reason can be personal: it stays out of the audit trail.
    expect(json_encode(DB::table('audit_logs')->where('action', 'scheduling.exception_created')->value('changes')))->not->toContain('Family trip');

    $types = ['CLINIC_CLOSURE' => 1, 'TEMPORARY_UNAVAILABILITY' => 3, 'SPECIAL_HOURS' => 4];
    foreach ($types as $type => $priority) {
        $extra = $type === 'SPECIAL_HOURS' ? ['clinic_location_id' => $this->locationId, 'doctor_service_mode_id' => $this->mode] : [];
        $this->postJson('/api/v1/schedule-exceptions', leave(['exception_type' => $type, ...$extra]), $this->doctorHeaders)->assertCreated()->assertJsonPath('data.priority', $priority);
    }
});

it('flags overlapping appointments for rescheduling and never cancels them', function () {
    $patient = (string) PatientProfile::where('user_id', $this->doctor->user_id)->value('id');
    $at = now()->addDays(11)->setTime(1, 0);
    $appointment = (string) Str::uuid7();
    DB::table('appointments')->insert(['id' => $appointment, 'reference_code' => 'MC-TEST01', 'patient_profile_id' => $patient, 'booked_by_user_id' => $this->doctor->user_id, 'doctor_profile_id' => $this->doctor->id,
        'clinic_location_id' => $this->locationId, 'doctor_service_id' => $this->service, 'doctor_service_mode_id' => $this->mode, 'consultation_type' => 'IN_PERSON',
        'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(20), 'timezone' => 'Asia/Manila', 'status' => 'CONFIRMED', 'payer_type' => 'SELF_PAY', 'fee_amount' => 65000]);

    $this->postJson('/api/v1/schedule-exceptions', leave(['exception_type' => 'SICK_LEAVE']), $this->doctorHeaders)->assertCreated()->assertJsonPath('data.affected_appointments', [$appointment]);
    $this->postJson('/api/v1/schedule-exceptions', leave(['exception_type' => 'EMERGENCY_LEAVE']), $this->doctorHeaders)->assertCreated(); // a second block keeps one open task

    expect(DB::table('appointments')->where('id', $appointment)->first())->status->toBe('CONFIRMED')->needs_rescheduling->toBeTrue()
        ->and(DB::table('rescheduling_tasks')->where('appointment_id', $appointment)->where('status', 'OPEN')->count())->toBe(1)
        ->and(DB::table('rescheduling_tasks')->where('appointment_id', $appointment)->value('reason'))->toBe('EXCEPTION_ADDED');
});

it('validates types, effects, times and the service for added hours', function () {
    $this->postJson('/api/v1/schedule-exceptions', leave(['effect' => 'ADD_HOURS']), $this->doctorHeaders)->assertStatus(422)->assertJsonPath('errors.effect.0', 'Only special hours or a requested change can add time.');
    $this->postJson('/api/v1/schedule-exceptions', leave(['exception_type' => 'SPECIAL_HOURS', 'clinic_location_id' => $this->locationId]), $this->doctorHeaders)
        ->assertStatus(422)->assertJsonPath('errors.doctor_service_mode_id.0', 'Choose the appointment service these extra hours are for.');
    $this->postJson('/api/v1/schedule-exceptions', leave(['exception_type' => 'SPECIAL_HOURS', 'doctor_service_mode_id' => $this->mode]), $this->doctorHeaders)
        ->assertStatus(422)->assertJsonPath('errors.clinic_location_id.0', 'Extra hours are at the place of their service.');
    $this->postJson('/api/v1/schedule-exceptions', leave(['starts_at' => now()->subDay()->toIso8601String()]), $this->doctorHeaders)->assertStatus(422)->assertJsonPath('errors.starts_at.0', 'An exception must start in the future.');
    $this->postJson('/api/v1/schedule-exceptions', leave(['ends_at' => now()->addDays(5)->toIso8601String()]), $this->doctorHeaders)->assertStatus(422)->assertJsonPath('errors.ends_at.0', 'An exception must end after it starts.');
    $this->postJson('/api/v1/schedule-exceptions', leave(['exception_type' => 'DAY_OFF']), $this->doctorHeaders)->assertStatus(422);
});

it('lets the clinic record exceptions at its location only; all-places exceptions stay with the doctor', function () {
    $this->postJson('/api/v1/schedule-exceptions', leave(['doctor_profile_id' => $this->doctor->id, 'exception_type' => 'CLINIC_CLOSURE', 'clinic_location_id' => $this->locationId]), $this->manager)->assertCreated();
    $this->postJson('/api/v1/schedule-exceptions', leave(['doctor_profile_id' => $this->doctor->id]), $this->manager)->assertForbidden();
    $this->getJson("/api/v1/schedule-exceptions?clinic_id={$this->clinicId}", $this->manager)->assertOk()->assertJsonCount(1, 'data');
    app('auth')->forgetGuards();

    [$otherClinic, $otherLocation, $otherManager] = clinic_with_manager('Diliman Medical Clinic');
    $this->postJson('/api/v1/schedule-exceptions', leave(['doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->locationId]), $otherManager)->assertForbidden();
    $id = DB::table('doctor_schedule_exceptions')->value('id');
    $this->deleteJson("/api/v1/schedule-exceptions/{$id}", [], $otherManager)->assertForbidden();
});

it('cancels an exception once, keeping the record', function () {
    $id = $this->postJson('/api/v1/schedule-exceptions', leave(), $this->doctorHeaders)->json('data.id');

    $this->deleteJson("/api/v1/schedule-exceptions/{$id}", [], $this->doctorHeaders)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->deleteJson("/api/v1/schedule-exceptions/{$id}", [], $this->doctorHeaders)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    expect(DB::table('doctor_schedule_exceptions')->where('id', $id)->value('cancelled_by'))->toBe($this->doctor->user_id)
        ->and(DB::table('audit_logs')->where('action', 'scheduling.exception_cancelled')->count())->toBe(1)
        ->and($this->getJson('/api/v1/schedule-exceptions', $this->doctorHeaders)->json('data'))->toBe([]);
});
