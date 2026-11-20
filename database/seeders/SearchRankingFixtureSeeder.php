<?php

namespace Database\Seeders;

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Models\Organization;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Modules\Users\Models\User;
use App\Shared\Services\Fingerprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic doctors for judging search ranking (increment 3.5): names,
 * specialties and cities chosen so the judged queries in
 * `data/search-judgments.php` have known answers. LOCAL and testing only;
 * every name is invented. Run `search:rebuild doctors` afterwards.
 */
final class SearchRankingFixtureSeeder extends Seeder
{
    /** name, specialty, city, profile completeness, online */
    private const DOCTORS = [
        ['Dr. Maria Santos', 'Pediatrics', 'Makati', 90, true],
        ['Dr. Carla Mendoza', 'Pediatrics', 'Quezon City', 70, false],
        ['Dr. Paolo Lim', 'Pediatrics', 'Pasig', 55, true],
        ['Dr. Jose Reyes', 'Cardiology', 'Quezon City', 85, false],
        ['Dr. Mark Santos', 'Cardiology', 'Makati', 60, true],
        ['Dr. Ramon Garcia', 'Cardiology', 'Cebu City', 75, false],
        ['Dr. Ana Cruz', 'Dermatology', 'Makati', 80, true],
        ['Dr. Bea Torres', 'Dermatology', 'Pasig', 50, false],
        ['Dr. Liza Bautista', 'Obstetrics and Gynecology', 'Pasig', 95, false],
        ['Dr. Grace Villanueva', 'Obstetrics and Gynecology', 'Cebu City', 65, true],
        ['Dr. Noel Aquino', 'Otorhinolaryngology', 'Quezon City', 70, false],
        ['Dr. Irene Ramos', 'Ophthalmology', 'Makati', 60, false],
        ['Dr. Victor Dela Cruz', 'Internal Medicine', 'Quezon City', 88, true],
        ['Dr. Joy Fernandez', 'Internal Medicine', 'Cebu City', 45, false],
        ['Dr. Tess Navarro', 'Family Medicine', 'Pasig', 72, true],
    ];

    private const CLINICS = [
        'Makati' => 'Ayala Family Clinic',
        'Quezon City' => 'Diliman Medical Clinic',
        'Pasig' => 'Ortigas Health Center',
        'Cebu City' => 'Lahug Specialty Clinic',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The search ranking fixture is for LOCAL and testing only.');
        }
        $locations = [];
        foreach (self::CLINICS as $city => $name) {
            $org = new Organization;
            $org->forceFill(['name' => $name.' Group', 'organization_type' => 'CLINIC_GROUP'])->save();
            $clinic = new Clinic;
            $clinic->forceFill(['organization_id' => $org->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4))])->save();
            $location = new ClinicLocation;
            $location->forceFill(['clinic_id' => $clinic->id, 'name' => 'Main branch', 'address_line1' => 'Sample St', 'city' => $city])->save();
            $locations[$city] = $location;
        }
        $specialties = DB::table('specialties')->pluck('id', 'name');

        foreach (self::DOCTORS as [$name, $specialty, $city, $completeness, $online]) {
            $user = User::factory()->create();
            $profile = new DoctorProfile(['display_name' => $name, 'professional_title' => 'MD']);
            $profile->user_id = $user->id;
            $licence = (string) random_int(1000000, 9999999);
            $profile->prc_license_number_encrypted = $licence;
            $profile->prc_license_number_hash = Fingerprint::of('prc:'.$licence);
            $profile->forceFill(['onboarding_status' => 'ACTIVE', 'license_verification_status' => 'VERIFIED', 'verified_at' => now(), 'profile_completeness' => $completeness])->save();
            (new DoctorSpecialty(['specialty_id' => $specialties[$specialty], 'is_primary' => true]))
                ->forceFill(['doctor_profile_id' => $profile->id, 'verification_status' => 'CONFIRMED', 'confirmed_by' => $user->id, 'confirmed_at' => now()])->save();
            $location = $locations[$city];
            (new DoctorClinicAssignment)->forceFill(['doctor_profile_id' => $profile->id, 'clinic_location_id' => $location->id, 'status' => 'ACTIVE', 'requested_by' => $user->id, 'approved_by' => $user->id, 'effective_from' => now()])->save();
            (new DoctorService)->forceFill(['doctor_profile_id' => $profile->id, 'clinic_location_id' => $location->id, 'name' => 'Consultation', 'consultation_type' => 'IN_PERSON', 'duration_minutes' => 20, 'fee_amount' => 50000 + $completeness * 100, 'effective_from' => now(), 'created_by' => $user->id])->save();
            if ($online) {
                (new DoctorService)->forceFill(['doctor_profile_id' => $profile->id, 'name' => 'Teleconsultation', 'consultation_type' => 'ONLINE', 'duration_minutes' => 15, 'fee_amount' => 45000, 'effective_from' => now(), 'created_by' => $user->id])->save();
            }
        }
    }
}
