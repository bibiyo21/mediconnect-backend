<?php

use App\Modules\Discovery\Services\DoctorDocuments;
use Database\Seeders\PerformanceDatasetSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 3.7: the synthetic performance dataset. Bulk-loaded without
 * outbox events, tagged, removable, and refused outside LOCAL and testing.
 */

beforeEach(function () {
    $this->seed(SpecialtySeeder::class);
});

it('loads tagged, searchable doctors without flooding the outbox', function () {
    $outbox = DB::table('outbox_events')->count();
    (new PerformanceDatasetSeeder(doctors: 40, locations: 10))->run();

    expect(DB::table('users')->where('email', 'like', '%@'.PerformanceDatasetSeeder::EMAIL_DOMAIN)->count())->toBe(40)
        ->and(DB::table('doctor_profiles')->where('onboarding_status', 'ACTIVE')->count())->toBe(40)
        ->and(DB::table('clinic_locations')->count())->toBe(10)
        ->and(DB::table('clinic_locations')->whereNull('latitude')->count())->toBe(0)
        ->and(DB::table('outbox_events')->count())->toBe($outbox);

    $doctor = (string) DB::table('doctor_profiles')->value('id');
    $documents = app(DoctorDocuments::class)->forDoctor($doctor);
    expect($documents)->not->toBeEmpty()
        ->and($documents[0]['specialties'])->toHaveCount(1)
        ->and(implode(' ', array_keys($documents[0])))->not->toContain('license');
});

it('gives the same names for the same seed', function () {
    (new PerformanceDatasetSeeder(doctors: 15, locations: 5, seed: 7))->run();
    $first = DB::table('doctor_profiles')->orderBy('display_name')->pluck('display_name')->all();
    PerformanceDatasetSeeder::remove();
    (new PerformanceDatasetSeeder(doctors: 15, locations: 5, seed: 7))->run();

    expect(DB::table('doctor_profiles')->orderBy('display_name')->pluck('display_name')->all())->toBe($first);
});

it('removes only its own rows', function () {
    seed_roles();
    $kept = directory_doctor('ACTIVE', 'Dr. Real Fixture');
    (new PerformanceDatasetSeeder(doctors: 25, locations: 6))->run();

    expect(PerformanceDatasetSeeder::remove())->toBe(25)
        ->and(PerformanceDatasetSeeder::present())->toBeFalse()
        ->and(DB::table('doctor_profiles')->pluck('display_name')->all())->toBe(['Dr. Real Fixture'])
        ->and(DB::table('users')->where('email', 'like', '%@'.PerformanceDatasetSeeder::EMAIL_DOMAIN)->count())->toBe(0)
        ->and(DB::table('organizations')->where('legal_name', PerformanceDatasetSeeder::ORGANIZATION_MARKER)->count())->toBe(0);
});

it('refuses to load twice', function () {
    (new PerformanceDatasetSeeder(doctors: 5, locations: 2))->run();
    (new PerformanceDatasetSeeder(doctors: 5, locations: 2))->run();
})->throws(RuntimeException::class, 'already loaded');

it('refuses outside LOCAL and testing', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    expect(fn () => (new PerformanceDatasetSeeder(doctors: 5, locations: 2))->run())->toThrow(RuntimeException::class, 'LOCAL and testing only')
        ->and(fn () => PerformanceDatasetSeeder::remove())->toThrow(RuntimeException::class);
    $this->artisan('perf:dataset')->assertFailed();
    expect(DB::table('doctor_profiles')->count())->toBe(0);
})->with(['production', 'staging']);
