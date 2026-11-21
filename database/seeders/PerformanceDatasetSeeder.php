<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic directory for the search performance baseline (increment 3.7,
 * Phase 3 plan Q4): by default 10,000 ACTIVE doctors across 2,000 clinic
 * locations, about 25,000 search documents. LOCAL and testing only; every
 * name is invented and no licence numbers, contacts or documents are created.
 *
 * Rows are bulk-inserted, so the directory observers do not fire and the
 * outbox and `search` queue are not flooded: index once afterwards with
 * `search:rebuild all`. Every row is tagged (users by email domain,
 * organizations by legal name), and remove() deletes exactly those rows.
 * Content is deterministic for a given seed, so reruns give the same dataset.
 */
final class PerformanceDatasetSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'perf.mediconnect.test';

    public const ORGANIZATION_MARKER = 'MediConnect synthetic performance dataset';

    private const FIRST_NAMES = [
        'Maria', 'Jose', 'Ana', 'Juan', 'Mark', 'Grace', 'Paolo', 'Carla', 'Ramon', 'Liza', 'Noel', 'Irene', 'Victor', 'Joy', 'Tess', 'Ben',
        'Andrea', 'Miguel', 'Patricia', 'Carlo', 'Kristine', 'Rafael', 'Angelica', 'Gabriel', 'Camille', 'Joshua', 'Bea', 'Daniel', 'Nicole', 'Paulo',
        'Sheila', 'Arnel', 'Rowena', 'Jerome', 'Marites', 'Edgar', 'Cristina', 'Rodel', 'Jennifer', 'Alvin', 'Rhea', 'Dennis', 'Lourdes', 'Ricardo',
        'Abigail', 'Francis', 'Charmaine', 'Rey', 'Katrina', 'Emmanuel', 'Mylene', 'Christian', 'Divina', 'Ronaldo', 'Aileen', 'Jaime', 'Marivic', 'Oscar',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada', 'Castillo', 'Flores', 'Villanueva', 'Ramos',
        'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salazar', 'Mercado', 'Aguilar', 'Domingo', 'Gonzales', 'Lopez', 'Fernandez', 'Dela Cruz', 'De Leon',
        'Pascual', 'Valdez', 'Gutierrez', 'Soriano', 'Manalo', 'Santiago', 'Lim', 'Tan', 'Sy', 'Go', 'Chua', 'Javier', 'Del Rosario', 'Marquez',
        'Francisco', 'Morales', 'Robles', 'Padilla', 'Diaz', 'Sison', 'Lacson', 'Agustin', 'Panganiban', 'Evangelista', 'Galang', 'Dizon', 'Tolentino',
    ];

    /** city, province, approximate centre (lat, lng), weight: Metro Manila carries the most doctors. */
    private const CITIES = [
        ['Quezon City', 'Metro Manila', 14.6760, 121.0437, 12], ['Manila', 'Metro Manila', 14.5995, 120.9842, 10],
        ['Makati', 'Metro Manila', 14.5547, 121.0244, 9], ['Pasig', 'Metro Manila', 14.5764, 121.0851, 7],
        ['Taguig', 'Metro Manila', 14.5176, 121.0509, 6], ['Mandaluyong', 'Metro Manila', 14.5794, 121.0359, 4],
        ['San Juan', 'Metro Manila', 14.6019, 121.0355, 3], ['Paranaque', 'Metro Manila', 14.4793, 121.0198, 3],
        ['Caloocan', 'Metro Manila', 14.6507, 120.9668, 3], ['Las Pinas', 'Metro Manila', 14.4445, 120.9939, 2],
        ['Muntinlupa', 'Metro Manila', 14.4081, 121.0415, 2], ['Marikina', 'Metro Manila', 14.6507, 121.1029, 2],
        ['Cebu City', 'Cebu', 10.3157, 123.8854, 6], ['Mandaue', 'Cebu', 10.3236, 123.9223, 2], ['Lapu-Lapu', 'Cebu', 10.3103, 123.9494, 1],
        ['Davao City', 'Davao del Sur', 7.1907, 125.4553, 5], ['Iloilo City', 'Iloilo', 10.7202, 122.5621, 2], ['Bacolod', 'Negros Occidental', 10.6765, 122.9509, 2],
        ['Cagayan de Oro', 'Misamis Oriental', 8.4542, 124.6319, 2], ['Baguio', 'Benguet', 16.4023, 120.5960, 2], ['Angeles', 'Pampanga', 15.1450, 120.5887, 2],
        ['Antipolo', 'Rizal', 14.5860, 121.1761, 2], ['Bacoor', 'Cavite', 14.4590, 120.9290, 2], ['Dasmarinas', 'Cavite', 14.3294, 120.9367, 1],
        ['Calamba', 'Laguna', 14.2117, 121.1653, 1], ['Batangas City', 'Batangas', 13.7565, 121.0583, 1], ['Zamboanga City', 'Zamboanga del Sur', 6.9214, 122.0790, 1],
        ['General Santos', 'South Cotabato', 6.1164, 125.1716, 1], ['Tacloban', 'Leyte', 11.2433, 125.0048, 1], ['Dumaguete', 'Negros Oriental', 9.3068, 123.3054, 1],
    ];

    /** Rough share of doctors by specialty; anything in the catalogue but not listed gets weight 1. */
    private const SPECIALTY_WEIGHTS = [
        'Internal Medicine' => 14, 'Pediatrics' => 12, 'Obstetrics and Gynecology' => 10, 'Family Medicine' => 10, 'General Surgery' => 5,
        'Cardiology' => 4, 'Dermatology' => 4, 'Ophthalmology' => 3, 'Otorhinolaryngology' => 3, 'Orthopedics' => 3, 'Psychiatry' => 2,
        'Dentistry' => 4, 'Endocrinology' => 2, 'Gastroenterology' => 2, 'Pulmonology' => 2, 'Neurology' => 2,
    ];

    private const CLINIC_KINDS = ['Medical Clinic', 'Family Clinic', 'Health Center', 'Medical Arts Building', 'Diagnostic and Medical Center', 'Specialty Clinic', 'Polyclinic'];

    private const AREAS = ['Poblacion', 'San Antonio', 'San Isidro', 'Santa Cruz', 'Bagong Silang', 'Mabini', 'Rizal', 'Bonifacio', 'Del Pilar', 'Malvar', 'Riverside', 'Highway', 'Plaza', 'Centro', 'Burgos', 'Luna'];

    public function __construct(public int $doctors = 10_000, public int $locations = 2_000, public int $seed = 37) {}

    public function run(): void
    {
        self::guard();
        if (self::present()) {
            throw new RuntimeException('The performance dataset is already loaded. Remove it first (perf:dataset --remove).');
        }
        mt_srand($this->seed);
        $now = now();
        $password = Hash::make(Str::random(40)); // nobody can sign in as these accounts

        $locationIds = $this->seedClinics($now);
        $specialties = $this->specialtyPicker();

        for ($start = 0; $start < $this->doctors; $start += 500) {
            DB::transaction(function () use ($start, $now, $password, $locationIds, $specialties) {
                $rows = ['users' => [], 'doctor_profiles' => [], 'doctor_specialties' => [], 'doctor_languages' => [], 'doctor_clinic_assignments' => [], 'doctor_services' => []];
                for ($n = $start; $n < min($start + 500, $this->doctors); $n++) {
                    $userId = (string) Str::uuid7();
                    $doctorId = (string) Str::uuid7();
                    $completeness = mt_rand(40, 100);
                    $rows['users'][] = ['id' => $userId, 'email' => sprintf('perf-doctor-%05d@%s', $n, self::EMAIL_DOMAIN), 'password' => $password, 'email_verified_at' => $now, 'status' => 'ACTIVE', 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now];
                    $rows['doctor_profiles'][] = [
                        'id' => $doctorId, 'user_id' => $userId,
                        'display_name' => 'Dr. '.self::pick(self::FIRST_NAMES).' '.self::pick(self::LAST_NAMES),
                        'professional_title' => 'MD', 'onboarding_status' => 'ACTIVE', 'license_verification_status' => 'VERIFIED', 'verified_at' => $now,
                        'years_experience' => mt_rand(1, 40), 'gender' => mt_rand(0, 1) ? 'FEMALE' : 'MALE', 'profile_completeness' => $completeness,
                        'review_count' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ];
                    $rows['doctor_specialties'][] = ['id' => (string) Str::uuid7(), 'doctor_profile_id' => $doctorId, 'specialty_id' => $specialties(), 'is_primary' => true, 'board_certified' => mt_rand(0, 1) === 1, 'verification_status' => 'CONFIRMED', 'confirmed_by' => $userId, 'confirmed_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                    $rows['doctor_languages'][] = ['doctor_profile_id' => $doctorId, 'language_code' => 'en'];
                    if (mt_rand(1, 100) <= 70) {
                        $rows['doctor_languages'][] = ['doctor_profile_id' => $doctorId, 'language_code' => 'fil'];
                    }

                    // 1 to 3 practice locations (mean about 1.9), and online for 60%: about 25,000 documents for 10,000 doctors.
                    $roll = mt_rand(1, 100);
                    $count = $roll <= 35 ? 1 : ($roll <= 75 ? 2 : 3);
                    $fee = 30_000 + 5_000 * mt_rand(0, 34); // PHP 300 to 2,000, in centavos
                    foreach (self::distinct($locationIds, $count) as $locationId) {
                        $rows['doctor_clinic_assignments'][] = ['id' => (string) Str::uuid7(), 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $locationId, 'status' => 'ACTIVE', 'requested_by' => $userId, 'approved_by' => $userId, 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now];
                        $rows['doctor_services'][] = self::service($doctorId, $locationId, 'Consultation', 'IN_PERSON', 20, $fee, $userId, $now);
                    }
                    if (mt_rand(1, 100) <= 60) {
                        $rows['doctor_services'][] = self::service($doctorId, null, 'Teleconsultation', 'ONLINE', 15, max(30_000, $fee - 10_000), $userId, $now);
                    }
                }
                foreach ($rows as $table => $batch) {
                    DB::table($table)->insert($batch);
                }
            });
        }
    }

    /** Deletes every tagged row, children first; returns the number of doctors removed. */
    public static function remove(): int
    {
        self::guard();

        return DB::transaction(function () {
            $users = DB::table('users')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
            $doctors = DB::table('doctor_profiles')->whereIn('user_id', $users)->pluck('id');
            foreach ($doctors->chunk(1000) as $chunk) {
                foreach (['doctor_services', 'doctor_clinic_assignments', 'doctor_languages', 'doctor_specialties'] as $table) {
                    DB::table($table)->whereIn('doctor_profile_id', $chunk)->delete();
                }
                DB::table('doctor_profiles')->whereIn('id', $chunk)->delete();
            }
            foreach ($users->chunk(1000) as $chunk) {
                DB::table('users')->whereIn('id', $chunk)->delete();
            }
            $organizations = DB::table('organizations')->where('legal_name', self::ORGANIZATION_MARKER)->pluck('id');
            $clinics = DB::table('clinics')->whereIn('organization_id', $organizations)->pluck('id');
            DB::table('clinic_locations')->whereIn('clinic_id', $clinics)->delete();
            DB::table('clinics')->whereIn('id', $clinics)->delete();
            DB::table('organizations')->whereIn('id', $organizations)->delete();

            return $doctors->count();
        });
    }

    public static function present(): bool
    {
        return DB::table('organizations')->where('legal_name', self::ORGANIZATION_MARKER)->exists();
    }

    private static function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The performance dataset is for LOCAL and testing only.');
        }
    }

    /** @return list<string> location ids, spread over cities by weight */
    private function seedClinics(\DateTimeInterface $now): array
    {
        $cities = [];
        foreach (self::CITIES as $city) {
            array_push($cities, ...array_fill(0, $city[4], $city));
        }
        $locationIds = [];
        $made = 0;
        $clinicNo = 0;
        while ($made < $this->locations) {
            $orgId = (string) Str::uuid7();
            $clinicId = (string) Str::uuid7();
            $branches = min(mt_rand(1, 100) <= 80 ? 1 : mt_rand(2, 6), $this->locations - $made);
            $name = mt_rand(0, 1) ? self::pick(self::LAST_NAMES).' '.self::pick(self::CLINIC_KINDS) : self::pick(self::AREAS).' '.self::pick(self::CLINIC_KINDS);
            DB::table('organizations')->insert(['id' => $orgId, 'name' => $name, 'legal_name' => self::ORGANIZATION_MARKER, 'organization_type' => 'CLINIC_GROUP', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('clinics')->insert(['id' => $clinicId, 'organization_id' => $orgId, 'name' => $name, 'slug' => Str::slug($name).'-perf-'.(++$clinicNo), 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            $rows = [];
            for ($b = 0; $b < $branches; $b++) {
                [$city, $province, $lat, $lng] = $cities[mt_rand(0, count($cities) - 1)];
                $id = (string) Str::uuid7();
                $locationIds[] = $id;
                $rows[] = [
                    'id' => $id, 'clinic_id' => $clinicId, 'name' => $b === 0 ? 'Main branch' : 'Branch '.($b + 1),
                    'address_line1' => mt_rand(1, 999).' '.self::pick(self::AREAS).' Street', 'barangay' => self::pick(self::AREAS), 'city' => $city, 'province' => $province,
                    // Within about 5 km of the city centre: synthetic points for "near me", not real addresses.
                    'latitude' => round($lat + (mt_rand(-450, 450) / 10_000), 6), 'longitude' => round($lng + (mt_rand(-450, 450) / 10_000), 6),
                    'country_code' => 'PH', 'timezone' => 'Asia/Manila', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('clinic_locations')->insert($rows);
            $made += $branches;
        }

        return $locationIds;
    }

    /** @return callable(): string a weighted random specialty id from the catalogue */
    private function specialtyPicker(): callable
    {
        $pool = [];
        foreach (DB::table('specialties')->where('is_active', true)->orderBy('name')->get(['id', 'name']) as $s) {
            array_push($pool, ...array_fill(0, self::SPECIALTY_WEIGHTS[$s->name] ?? 1, (string) $s->id));
        }
        if ($pool === []) {
            throw new RuntimeException('Seed the specialty catalogue first (SpecialtySeeder).');
        }

        return fn () => $pool[mt_rand(0, count($pool) - 1)];
    }

    /** @return array<string, mixed> */
    private static function service(string $doctorId, ?string $locationId, string $name, string $type, int $minutes, int $fee, string $userId, \DateTimeInterface $now): array
    {
        return ['id' => (string) Str::uuid7(), 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $locationId, 'name' => $name, 'consultation_type' => $type, 'duration_minutes' => $minutes, 'buffer_minutes' => 0, 'fee_amount' => $fee, 'currency' => 'PHP', 'is_follow_up' => false, 'is_hmo_eligible' => false, 'status' => 'ACTIVE', 'effective_from' => $now, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
    }

    /**
     * @param  list<string>  $items
     * @return list<string> $count different items
     */
    private static function distinct(array $items, int $count): array
    {
        $picked = [];
        while (count($picked) < min($count, count($items))) {
            $picked[$items[mt_rand(0, count($items) - 1)]] = true;
        }

        return array_keys($picked);
    }

    /** @param  list<string>  $items */
    private static function pick(array $items): string
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
