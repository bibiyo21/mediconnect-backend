<?php

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Models\Organization;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Modules\Specialties\Models\Specialty;
use App\Shared\Services\Fingerprint;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 2.11 exit tests: unverified doctors are never listed, responses
 * carry no private fields, and inactive clinics or locations stay hidden.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
});

function directory_clinic(string $name = 'Santos Family Clinic', string $city = 'Makati', string $status = 'ACTIVE'): ClinicLocation
{
    $org = new Organization;
    $org->forceFill(['name' => $name.' Group', 'organization_type' => 'CLINIC_GROUP', 'tin_encrypted' => 'secret-tin'])->save();
    $clinic = new Clinic;
    $clinic->forceFill(['organization_id' => $org->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'status' => $status])->save();
    $location = new ClinicLocation;
    $location->forceFill(['clinic_id' => $clinic->id, 'name' => 'Main branch', 'address_line1' => '12 Mabini St', 'city' => $city, 'phone' => '+63281234567'])->save();
    DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $location->id, 'day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '17:00', 'created_at' => now(), 'updated_at' => now()]);

    return $location;
}

/** A doctor in the given onboarding state, with one confirmed and one self-reported specialty. */
function directory_doctor(string $status, string $name = 'Dr. Ana Santos', ?ClinicLocation $at = null): DoctorProfile
{
    $user = patient_account();
    $profile = new DoctorProfile(['display_name' => $name, 'biography' => 'Pediatrician in Makati.']);
    $profile->user_id = $user->id;
    $licence = (string) random_int(1000000, 9999999);
    $profile->prc_license_number_encrypted = $licence;
    $profile->prc_license_number_hash = Fingerprint::of('prc:'.$licence);
    $profile->save();
    $verified = in_array($status, ['VERIFIED', 'ACTIVE'], true);
    DB::table('doctor_profiles')->where('id', $profile->id)->update(['onboarding_status' => $status, 'license_verification_status' => $verified ? 'VERIFIED' : 'NOT_VERIFIED', 'verified_at' => $verified ? now() : null]);
    [$confirmed, $selfReported] = Specialty::orderBy('name')->take(2)->get()->all();
    (new DoctorSpecialty(['specialty_id' => $confirmed->id, 'is_primary' => true]))->forceFill(['doctor_profile_id' => $profile->id, 'verification_status' => 'CONFIRMED', 'confirmed_by' => $user->id, 'confirmed_at' => now()])->save();
    (new DoctorSpecialty(['specialty_id' => $selfReported->id]))->forceFill(['doctor_profile_id' => $profile->id])->save();
    DB::table('doctor_languages')->insert([['doctor_profile_id' => $profile->id, 'language_code' => 'en'], ['doctor_profile_id' => $profile->id, 'language_code' => 'fil']]);

    if ($at !== null) {
        (new DoctorClinicAssignment)->forceFill(['doctor_profile_id' => $profile->id, 'clinic_location_id' => $at->id, 'status' => 'ACTIVE', 'requested_by' => $user->id, 'approved_by' => $user->id, 'effective_from' => now()])->save();
        (new DoctorService)->forceFill(['doctor_profile_id' => $profile->id, 'clinic_location_id' => $at->id, 'name' => 'Consultation', 'consultation_type' => 'IN_PERSON', 'duration_minutes' => 20, 'fee_amount' => 65000, 'effective_from' => now(), 'created_by' => $user->id])->save();
    }
    (new DoctorService)->forceFill(['doctor_profile_id' => $profile->id, 'name' => 'Teleconsultation', 'consultation_type' => 'ONLINE', 'duration_minutes' => 15, 'fee_amount' => 50000, 'effective_from' => now(), 'created_by' => $user->id])->save();

    return $profile->refresh();
}

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
