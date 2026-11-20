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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Directory fixtures shared by the public directory and search tests: an
 * active clinic location, and a doctor in a given onboarding state with one
 * confirmed and one self-reported specialty (needs SpecialtySeeder).
 */

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
