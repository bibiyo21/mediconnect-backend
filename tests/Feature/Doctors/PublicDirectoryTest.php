<?php

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Specialties\Models\Specialty;
use Database\Seeders\SpecialtySeeder;

/*
 * Increment 2.11 exit tests: unverified doctors are never listed, responses
 * carry no private fields, and inactive clinics or locations stay hidden.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
});

it('lists only ACTIVE doctors, with confirmed specialties and no private fields', function () {
    $location = directory_clinic();
    $active = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    foreach (['REGISTERED', 'DOCUMENTS_SUBMITTED', 'UNDER_REVIEW', 'REJECTED', 'VERIFIED'] as $i => $status) {
        directory_doctor($status, "Dr. Hidden {$i}");
    }

    $response = $this->getJson('/api/v1/doctors')->assertOk()->assertJsonPath('meta.total', 1);
    $doctor = $response->json('data.0');

    expect($doctor['id'])->toBe($active->id)
        ->and($doctor['licence_verified'])->toBeTrue()
        ->and($doctor['specialties'])->toHaveCount(1)
        ->and($doctor['languages'])->toBe(['en', 'fil'])
        ->and($doctor['practice_locations'][0]['clinic_name'])->toBe('Santos Family Clinic');
    $body = $response->getContent();
    foreach (['prc_license', 'user_id', 'onboarding_status', 'verification_history', 'credentials', 'profile_completeness', $active->prc_license_number_encrypted, 'email'] as $private) {
        expect($body)->not->toContain((string) $private);
    }
});

it('returns 404 for doctors who are not ACTIVE', function (string $status) {
    $doctor = directory_doctor($status);
    $this->getJson("/api/v1/doctors/{$doctor->id}")->assertNotFound();
    $this->getJson("/api/v1/doctors/{$doctor->id}/services")->assertNotFound();
})->with(['REGISTERED', 'UNDER_REVIEW', 'REJECTED', 'VERIFIED']);

it('shows a public profile and its services with fees', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);

    $profile = $this->getJson("/api/v1/doctors/{$doctor->id}")->assertOk()->json('data');
    expect($profile['biography'])->toBe('Pediatrician in Makati.')->and($profile)->not->toHaveKey('prc_license_number_masked');

    $services = $this->getJson("/api/v1/doctors/{$doctor->id}/services")->assertOk()->json('data');
    expect(collect($services)->pluck('fee.formatted')->sort()->values()->all())->toBe(['₱500.00', '₱650.00']);
});

it('hides services at a location the doctor no longer practises at', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    DoctorClinicAssignment::where('doctor_profile_id', $doctor->id)->update(['status' => 'ENDED', 'effective_until' => now()]);

    $services = $this->getJson("/api/v1/doctors/{$doctor->id}/services")->assertOk()->json('data');
    expect(collect($services)->pluck('consultation_type')->all())->toBe(['ONLINE']);
});

it('filters doctors by specialty, city, clinic and consultation type, and rejects unknown filters', function () {
    $makati = directory_clinic('Santos Family Clinic', 'Makati');
    $qc = directory_clinic('Reyes Clinic', 'Quezon City');
    $a = directory_doctor('ACTIVE', 'Dr. Ana Santos', $makati);
    $b = directory_doctor('ACTIVE', 'Dr. Ben Reyes', $qc);
    $selfReported = Specialty::orderBy('name')->skip(1)->firstOrFail();

    $this->getJson('/api/v1/doctors?filter[city]=makati')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
    $this->getJson("/api/v1/doctors?filter[clinic_id]={$qc->clinic_id}")->assertOk()->assertJsonPath('data.0.id', $b->id);
    $this->getJson('/api/v1/doctors?filter[consultation_type]=ONLINE')->assertOk()->assertJsonPath('meta.total', 2);
    // A self-reported specialty does not match (D-40).
    $this->getJson("/api/v1/doctors?filter[specialty_id]={$selfReported->id}")->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/doctors?filter[name]=Ana')->assertStatus(422);
});

it('lists only active clinics and locations, without staff or tax details', function () {
    $location = directory_clinic();
    directory_clinic('Closed Clinic', 'Makati', 'INACTIVE');
    $hidden = directory_clinic('Hidden Branch Clinic');
    ClinicLocation::whereKey($hidden->id)->update(['status' => 'INACTIVE']);
    directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);

    $list = $this->getJson('/api/v1/clinics')->assertOk()->assertJsonPath('meta.total', 1);
    expect($list->json('data.0.name'))->toBe('Santos Family Clinic')
        ->and($list->getContent())->not->toContain('secret-tin')->not->toContain('members');

    $clinic = $this->getJson("/api/v1/clinics/{$location->clinic_id}")->assertOk()->json('data');
    expect($clinic['locations'][0]['operating_hours'][0])->toBe(['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '17:00'])
        ->and($clinic)->toHaveKey('upcoming_closures');
    $this->getJson("/api/v1/clinics/{$location->clinic_id}/operating-hours")->assertOk()->assertJsonPath('data.0.location_name', 'Main branch');
    $this->getJson("/api/v1/clinics/{$location->clinic_id}/doctors")->assertOk()->assertJsonPath('data.0.display_name', 'Dr. Ana Santos');
    $this->getJson('/api/v1/clinics/'.Clinic::where('name', 'Closed Clinic')->value('id'))->assertNotFound();
});

it('lists only ACTIVE doctors on a clinic page', function () {
    $location = directory_clinic();
    directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    $verified = directory_doctor('VERIFIED', 'Dr. Not Yet Live');
    (new DoctorClinicAssignment)->forceFill(['doctor_profile_id' => $verified->id, 'clinic_location_id' => $location->id, 'status' => 'ACTIVE', 'requested_by' => $verified->user_id, 'approved_by' => $verified->user_id, 'effective_from' => now()])->save();

    $doctors = $this->getJson("/api/v1/clinics/{$location->clinic_id}/doctors")->assertOk()->json('data');
    expect(collect($doctors)->pluck('display_name')->all())->toBe(['Dr. Ana Santos']);
});
