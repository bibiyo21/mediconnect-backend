<?php

use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Exceptions\InvalidOnboardingTransition;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Services\DoctorOnboarding;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Specialties\Models\Specialty;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->seed(SpecialtySeeder::class);
});

function start_doctor(array $headers, array $overrides = []): array
{
    return test()->postJson('/api/v1/doctors', ['display_name' => 'Dr. Ana Santos', 'professional_title' => 'MD', 'confirm_licensed' => true, ...$overrides], $headers)->assertCreated()->json('data');
}

it('lets a patient start doctor onboarding and keep their patient profile (D-16)', function () {
    $user = patient_account();
    $headers = signed_in($user);

    $data = start_doctor($headers);

    expect($data['onboarding_status'])->toBe('REGISTERED')
        ->and($data['license_verification_status'])->toBe('NOT_VERIFIED')
        ->and($data['verification_history'][0]['to_status'])->toBe('REGISTERED')
        ->and($user->refresh()->getRoleNames()->sort()->values()->all())->toBe(['DOCTOR', 'PATIENT']);
    $this->getJson('/api/v1/patients/me', $headers)->assertOk();
    $this->getJson('/api/v1/doctors/me', $headers)->assertOk()->assertJsonPath('data.display_name', 'Dr. Ana Santos');
});

it('requires the licence confirmation and refuses a second profile', function () {
    $headers = signed_in(patient_account());
    $this->postJson('/api/v1/doctors', ['display_name' => 'Dr. X', 'confirm_licensed' => false], $headers)->assertStatus(422)->assertJsonValidationErrors('confirm_licensed', 'errors');
    start_doctor($headers);
    $this->postJson('/api/v1/doctors', ['display_name' => 'Dr. Y', 'confirm_licensed' => true], $headers)->assertStatus(422);
    expect(DoctorProfile::count())->toBe(1);
});

it('updates the profile, replaces lists, encrypts and masks the licence, and scores completeness', function () {
    $headers = signed_in(patient_account());
    start_doctor($headers);
    $cardiology = Specialty::where('slug', 'cardiology')->value('id');
    $internal = Specialty::where('slug', 'internal-medicine')->value('id');

    $data = $this->patchJson('/api/v1/doctors/me', [
        'biography' => str_repeat('Cardiologist caring for adults with heart disease. ', 2),
        'prc_license_number' => '0123456',
        'medical_school' => 'University of the Philippines College of Medicine',
        'years_experience' => 12,
        'languages' => ['en', 'fil'],
        'specialties' => [
            ['specialty_id' => $cardiology, 'is_primary' => true, 'board_certified' => true],
            ['specialty_id' => $internal],
        ],
        'education' => [['institution' => 'UP College of Medicine', 'degree' => 'Doctor of Medicine', 'year_completed' => 2010]],
        'experience' => [['organization' => 'Makati Medical Center', 'position' => 'Consultant', 'started_on' => '2014-06-01']],
    ], $headers)->assertOk()->json('data');

    expect($data['prc_license_number_masked'])->toBe('•••456')
        ->and($data['languages'])->toBe(['en', 'fil'])
        ->and(collect($data['specialties'])->firstWhere('is_primary', true)['specialty_name'])->toBe('Cardiology')
        ->and($data['profile_completeness'])->toBe(100);

    $row = DB::table('doctor_profiles')->first();
    expect($row->prc_license_number_encrypted)->not->toContain('0123456')->and($row->prc_license_number_hash)->not->toContain('0123456');

    $this->patchJson('/api/v1/doctors/me', ['languages' => ['ceb']], $headers)->assertOk()->assertJsonPath('data.languages', ['ceb']);
});

it('validates specialties, subspecialties, dates and the licence format', function (array $body, string $field) {
    $headers = signed_in(patient_account());
    start_doctor($headers);
    $this->patchJson('/api/v1/doctors/me', $body, $headers)->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
})->with([
    'no primary specialty' => [fn () => ['specialties' => [['specialty_id' => Specialty::where('slug', 'cardiology')->value('id')]]], 'specialties'],
    'unknown specialty' => [['specialties' => [['specialty_id' => '01a1ec01-0000-7000-8000-000000000999', 'is_primary' => true]]], 'specialties.0.specialty_id'],
    'licence with letters' => [['prc_license_number' => 'PRC-123'], 'prc_license_number'],
    'experience ends before it starts' => [['experience' => [['organization' => 'A', 'position' => 'B', 'started_on' => '2020-01-01', 'ended_on' => '2019-01-01']]], 'experience.0.ended_on'],
    'bad language code' => [['languages' => ['English']], 'languages.0'],
]);

it('refuses a licence number registered to another doctor', function () {
    $first = signed_in(patient_account());
    start_doctor($first);
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '7654321'], $first)->assertOk();
    $this->app['auth']->forgetGuards();

    $second = signed_in(patient_account());
    $this->app['auth']->forgetGuards();
    start_doctor($second, ['display_name' => 'Dr. Ben Cruz']);
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '7654321'], $second)->assertStatus(422)->assertJsonValidationErrors('prc_license_number', 'errors');
});

it('locks the licence number once the profile is under review', function () {
    $headers = signed_in(patient_account());
    start_doctor($headers);
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '1112223'], $headers)->assertOk();
    $profile = DoctorProfile::sole();
    $onboarding = app(DoctorOnboarding::class);
    $onboarding->transition($profile, OnboardingStatus::DocumentsSubmitted, $profile->user_id);
    $onboarding->transition($profile, OnboardingStatus::UnderReview, $profile->user_id);

    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '9998887'], $headers)->assertStatus(422);
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '1112223', 'biography' => 'Unchanged licence is fine'], $headers)->assertOk();
});

it('runs the state machine with an append-only history and refuses skipped steps', function () {
    $headers = signed_in(patient_account());
    start_doctor($headers);
    $profile = DoctorProfile::sole();
    $reviewer = account(Role::MedicalAdmin);
    $onboarding = app(DoctorOnboarding::class);

    expect(fn () => $onboarding->transition($profile, OnboardingStatus::Verified, $reviewer->id))->toThrow(InvalidOnboardingTransition::class);

    $onboarding->transition($profile, OnboardingStatus::DocumentsSubmitted, $profile->user_id);
    $onboarding->transition($profile, OnboardingStatus::UnderReview, $profile->user_id);
    $onboarding->transition($profile, OnboardingStatus::Rejected, $reviewer->id, 'The PRC ID photo is unreadable.');
    $onboarding->transition($profile, OnboardingStatus::DocumentsSubmitted, $profile->user_id);
    $onboarding->transition($profile, OnboardingStatus::UnderReview, $profile->user_id);
    $verified = $onboarding->transition($profile, OnboardingStatus::Verified, $reviewer->id);
    $active = $onboarding->transition($verified, OnboardingStatus::Active, $reviewer->id);

    expect($active->onboarding_status)->toBe(OnboardingStatus::Active)
        ->and($active->license_verification_status)->toBe('VERIFIED')
        ->and($active->verified_at)->not->toBeNull();

    $history = $this->getJson('/api/v1/doctors/me', $headers)->json('data.verification_history');
    expect(array_column($history, 'to_status'))->toBe(['REGISTERED', 'DOCUMENTS_SUBMITTED', 'UNDER_REVIEW', 'REJECTED', 'DOCUMENTS_SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'ACTIVE'])
        ->and($history[3]['reason'])->toBe('The PRC ID photo is unreadable.');

    expect(fn () => DB::transaction(fn () => DB::table('doctor_verification_events')->update(['reason' => 'tampered'])))->toThrow(QueryException::class);
});

it('keeps the database from marking a doctor verified without a verified licence', function () {
    $headers = signed_in(patient_account());
    start_doctor($headers);

    expect(fn () => DB::transaction(fn () => DB::table('doctor_profiles')->update(['onboarding_status' => 'ACTIVE'])))->toThrow(QueryException::class);
});

it('returns 404 for doctors/me without a doctor profile, and requires sign-in', function () {
    $this->getJson('/api/v1/doctors/me', signed_in(patient_account()))->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/doctors/me')->assertStatus(401);
});
