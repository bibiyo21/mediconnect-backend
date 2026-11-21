<?php

use App\Modules\Doctors\Models\DoctorService;
use Illuminate\Support\Facades\DB;

/*
 * Increment 2.10 exit tests: a clinic invites a verified doctor, the doctor
 * accepts; fees live on the server only; one doctor can have different fees
 * at different clinics; ending an assignment ends the services there.
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
});

it('invites a verified doctor by licence number; the doctor accepts', function () {
    [$clinicId, $locationId, $manager] = clinic_with_manager();
    [$doctor, $doctorHeaders] = verified_doctor();

    $invite = $this->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $locationId, 'prc_license_number' => '0123456'], $manager)->assertCreated()->json('data');
    expect($invite['status'])->toBe('PENDING')->and($invite['doctor_display_name'])->toBe('Dr. Ana Santos')->and($invite)->not->toHaveKey('prc_license_number');
    expect(collect($this->messages->notices)->pluck('notice')->implode(' '))->toContain('invited you');

    // A second invitation for the same place is refused.
    $this->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $locationId, 'prc_license_number' => '0123456'], $manager)->assertStatus(422);
    app('auth')->forgetGuards();

    $mine = $this->getJson('/api/v1/doctors/me/assignments', $doctorHeaders)->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0]['clinic_name'])->toBe('Santos Family Clinic');
    $this->postJson("/api/v1/doctors/me/assignments/{$invite['id']}/accept", [], $doctorHeaders)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $this->postJson("/api/v1/doctors/me/assignments/{$invite['id']}/accept", [], $doctorHeaders)->assertStatus(409);
    app('auth')->forgetGuards();

    $this->getJson("/api/v1/clinics/{$clinicId}/doctor-assignments", $manager)->assertOk()->assertJsonPath('data.0.status', 'ACTIVE');
});

it('finds only verified doctors, and only for managers of that clinic', function () {
    [$clinicId, $locationId, $manager] = clinic_with_manager();
    [, $otherLocation, $otherManager] = clinic_with_manager('Reyes Clinic');
    verified_doctor('1111111', 'REGISTERED');

    $this->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $locationId, 'prc_license_number' => '1111111'], $manager)->assertStatus(422)->assertJsonValidationErrors('prc_license_number');
    // A location of another clinic is not found here.
    $this->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $otherLocation, 'prc_license_number' => '1111111'], $manager)->assertNotFound();
    app('auth')->forgetGuards();
    // A manager of another clinic cannot invite or list here.
    $this->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $locationId, 'prc_license_number' => '1111111'], $otherManager)->assertForbidden();
    $this->getJson("/api/v1/clinics/{$clinicId}/doctor-assignments", $otherManager)->assertForbidden();
});

it('keeps fees per location: one doctor, two clinics, two fees', function () {
    [$clinicA, $locationA, $managerA] = clinic_with_manager();
    [$clinicB, $locationB, $managerB] = clinic_with_manager('Reyes Clinic');
    [, $doctorHeaders] = verified_doctor();
    assign($clinicA, $locationA, $managerA, $doctorHeaders);
    assign($clinicB, $locationB, $managerB, $doctorHeaders);

    $this->postJson('/api/v1/doctors/me/services', in_person($locationA, 65000), $doctorHeaders)->assertCreated()->assertJsonPath('data.fee.formatted', '₱650.00');
    $this->postJson('/api/v1/doctors/me/services', in_person($locationB, 80000), $doctorHeaders)->assertCreated()->assertJsonPath('data.fee.amount', 80000);
    $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $doctorHeaders)->assertCreated()->assertJsonPath('data.clinic_location_id', null);

    $this->postJson('/api/v1/doctors/me/services', in_person($locationA, 1, 'consultation'), $doctorHeaders)->assertStatus(422)->assertJsonValidationErrors('name');

    $services = $this->getJson('/api/v1/doctors/me/services', $doctorHeaders)->assertOk()->json('data');
    expect(collect($services)->pluck('fee.amount')->sort()->values()->all())->toBe([50000, 65000, 80000]);
});

it('needs an active assignment for in-person services, and a verified profile for any', function () {
    [, $locationId] = clinic_with_manager();
    [, $doctorHeaders] = verified_doctor();
    $this->postJson('/api/v1/doctors/me/services', in_person($locationId, 65000), $doctorHeaders)->assertStatus(422)->assertJsonValidationErrors('clinic_location_id');
    $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'clinic_location_id' => $locationId, 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $doctorHeaders)->assertStatus(422)->assertJsonValidationErrors('clinic_location_id');

    [, $registeredHeaders] = verified_doctor('2222222', 'REGISTERED');
    $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $registeredHeaders)->assertStatus(409);
});

it('validates the fee: required, whole centavos, never negative', function (mixed $fee) {
    [, $doctorHeaders] = verified_doctor();
    $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => $fee], $doctorHeaders)->assertStatus(422)->assertJsonValidationErrors('fee_amount');
})->with([null, -100, 650.5, '650.00', 100000001]);

it('changes a fee by ending the old row and starting a new one', function () {
    [, $doctorHeaders] = verified_doctor();
    $old = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $doctorHeaders)->json('data.id');

    $new = $this->patchJson("/api/v1/doctors/me/services/{$old}", ['fee_amount' => 55000], $doctorHeaders)->assertOk()->json('data');

    expect($new['id'])->not->toBe($old)->and($new['fee']['amount'])->toBe(55000);
    $oldRow = DoctorService::findOrFail($old);
    expect($oldRow->status)->toBe('ENDED')->and($oldRow->fee_amount)->toBe(50000)->and($oldRow->effective_until)->not->toBeNull()
        ->and(DoctorService::findOrFail($new['id'])->replaces_id)->toBe($old);
    // The ended row can no longer be changed.
    $this->patchJson("/api/v1/doctors/me/services/{$old}", ['fee_amount' => 1], $doctorHeaders)->assertNotFound();
    $this->deleteJson("/api/v1/doctors/me/services/{$new['id']}", [], $doctorHeaders)->assertOk();
    $this->getJson('/api/v1/doctors/me/services', $doctorHeaders)->assertOk()->assertJsonCount(0, 'data');
});

it("never lets a doctor change another doctor's service", function () {
    [, $owner] = verified_doctor();
    $id = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $owner)->json('data.id');
    [, $other] = verified_doctor('3333333');

    $this->patchJson("/api/v1/doctors/me/services/{$id}", ['fee_amount' => 1], $other)->assertNotFound();
    $this->deleteJson("/api/v1/doctors/me/services/{$id}", [], $other)->assertNotFound();
    expect(DoctorService::findOrFail($id)->fee_amount)->toBe(50000);
});

it('ends in-person services at a location when the clinic ends the assignment', function () {
    [$clinicId, $locationId, $manager] = clinic_with_manager();
    [, $doctorHeaders] = verified_doctor();
    $assignmentId = assign($clinicId, $locationId, $manager, $doctorHeaders);
    $service = $this->postJson('/api/v1/doctors/me/services', in_person($locationId, 65000), $doctorHeaders)->json('data.id');
    $online = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $doctorHeaders)->json('data.id');
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/clinics/{$clinicId}/doctor-assignments/{$assignmentId}/end", ['reason' => 'Moved to another branch'], $manager)->assertOk()->assertJsonPath('data.status', 'ENDED');

    expect(DoctorService::findOrFail($service)->status)->toBe('ENDED')
        ->and(DoctorService::findOrFail($online)->status)->toBe('ACTIVE')
        ->and(DB::table('audit_logs')->where('action', 'clinic.doctor_assignment_ended')->exists())->toBeTrue();
});

it('lets the doctor decline an invitation', function () {
    [$clinicId, $locationId, $manager] = clinic_with_manager();
    [, $doctorHeaders] = verified_doctor();
    $id = $this->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $locationId, 'prc_license_number' => '0123456'], $manager)->json('data.id');
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/doctors/me/assignments/{$id}/end", ['reason' => 'Not practising there'], $doctorHeaders)->assertOk()->assertJsonPath('data.status', 'ENDED');
    $this->postJson("/api/v1/doctors/me/assignments/{$id}/accept", [], $doctorHeaders)->assertStatus(409);
    $this->getJson('/api/v1/doctors/me/assignments', $doctorHeaders)->assertOk()->assertJsonCount(0, 'data');
});
