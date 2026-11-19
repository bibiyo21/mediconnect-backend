<?php

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Models\Organization;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Specialties\Models\Specialty;
use App\Shared\Contracts\MalwareScanner;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeMalwareScanner;

/*
 * Increment 2.9 exit tests: only verified doctors become ACTIVE; every
 * decision is audited; the doctor is notified; reviewers cannot decide on
 * their own profile; roles without doctors.verify are refused.
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->seed(SpecialtySeeder::class);
    Storage::fake('documents');
    app()->instance(MalwareScanner::class, new FakeMalwareScanner);
});

/** A doctor who has submitted a licence number, a clean PRC ID and one specialty. */
function submitted_doctor(): DoctorProfile
{
    $user = patient_account();
    $headers = signed_in($user);
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/doctors', ['display_name' => 'Dr. Ana Santos', 'confirm_licensed' => true], $headers)->assertCreated();
    $specialty = Specialty::firstOrFail();
    test()->patchJson('/api/v1/doctors/me', ['prc_license_number' => (string) random_int(1000000, 9999999), 'specialties' => [['specialty_id' => $specialty->id, 'is_primary' => true]]], $headers)->assertOk();
    test()->post('/api/v1/doctors/me/credentials', ['credential_type' => 'PRC_ID', 'file' => UploadedFile::fake()->createWithContent('prc.pdf', "%PDF-1.4\n%%EOF\n")], [...$headers, 'Accept' => 'application/json'])->assertCreated();
    test()->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertOk();
    app('auth')->forgetGuards();

    return DoctorProfile::where('user_id', $user->id)->firstOrFail();
}

/** The test client keeps the previous user (guards and the in-memory session) between requests. */
function switch_user(): void
{
    app('auth')->forgetGuards();
    app('session')->flush();
}

/** A reviewer session with MFA and, by default, a fresh step-up. */
function reviewer_session(Role $role = Role::MedicalAdmin, bool $stepUp = true): array
{
    switch_user();
    $reviewer = account($role);
    [$secret, , $access] = enroll_mfa($reviewer);
    if ($stepUp) {
        test()->travel(31)->seconds();
        test()->postJson('/api/v1/auth/mfa/step-up', ['code' => current_totp($secret)], bearer($access))->assertOk();
    }
    switch_user();

    return [$reviewer, bearer($access)];
}

it('lists the queue without licence numbers or documents', function () {
    $doctor = submitted_doctor();
    [, $headers] = reviewer_session(stepUp: false);

    $row = $this->getJson('/api/v1/admin/doctors', $headers)->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');

    expect($row['id'])->toBe($doctor->id)
        ->and($row['onboarding_status'])->toBe('DOCUMENTS_SUBMITTED')
        ->and($row['document_count'])->toBe(1)
        ->and($row['submitted_at'])->not->toBeNull()
        ->and($row)->not->toHaveKeys(['prc_license_number', 'credentials']);
    $this->getJson('/api/v1/admin/doctors?filter[onboarding_status]=ACTIVE', $headers)->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/admin/doctors?filter[nope]=1', $headers)->assertStatus(422);
});

it('refuses roles without doctors.verify, and reviewers without MFA', function () {
    submitted_doctor();
    [, $admin] = reviewer_session(Role::Admin, stepUp: false);
    $this->getJson('/api/v1/admin/doctors', $admin)->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/doctors', signed_in(patient_account()))->assertForbidden();
    app('auth')->forgetGuards();
    $limited = native_login(account(Role::MedicalAdmin))->json('data.tokens.access_token');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/doctors', bearer($limited))->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
});

it('runs the whole review: start, accept the document, confirm specialties, verify, activate', function () {
    $doctor = submitted_doctor();
    [$reviewer, $headers] = reviewer_session();

    $detail = $this->getJson("/api/v1/admin/doctors/{$doctor->id}", $headers)->assertOk()->json('data');
    expect($detail['prc_license_number'])->toMatch('/^\d{7}$/')
        ->and($detail['specialties'][0]['verification_status'])->toBe('SELF_REPORTED');
    $credentialId = $detail['credentials'][0]['id'];

    // Decisions need the review to have started.
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credentialId}/review", ['decision' => 'ACCEPTED'], $headers)->assertStatus(409);
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk()->assertJsonPath('data.onboarding_status', 'UNDER_REVIEW');

    // Verifying before the PRC ID is accepted is refused.
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/verify", ['reason' => 'Licence checked'], $headers)->assertStatus(422)->assertJsonValidationErrors('credentials');

    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credentialId}/review", ['decision' => 'ACCEPTED'], $headers)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/specialties/confirm", ['specialty_ids' => [$detail['specialties'][0]['id']]], $headers)->assertOk()->assertJsonPath('data.specialties.0.verification_status', 'CONFIRMED');

    // Cannot activate before verification.
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/activate", ['reason' => 'Ready to list'], $headers)->assertStatus(409);
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/verify", [], $headers)->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/verify", ['reason' => 'Licence checked on the PRC register'], $headers)->assertOk()->assertJsonPath('data.onboarding_status', 'VERIFIED');
    // D-41: no service yet, so activation is refused.
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/activate", ['reason' => 'Ready to list'], $headers)->assertStatus(422)->assertJsonValidationErrors('services');
    (new DoctorService)->forceFill(['doctor_profile_id' => $doctor->id, 'name' => 'Teleconsultation', 'consultation_type' => 'ONLINE', 'duration_minutes' => 15, 'fee_amount' => 50000, 'effective_from' => now(), 'created_by' => $doctor->user_id])->save();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/activate", ['reason' => 'Ready to list'], $headers)->assertOk()->assertJsonPath('data.onboarding_status', 'ACTIVE');

    $doctor->refresh();
    expect($doctor->license_verification_status)->toBe('VERIFIED')
        ->and($doctor->getAttribute('verified_by'))->toBe($reviewer->id)
        ->and(DB::table('doctor_verification_events')->where('doctor_profile_id', $doctor->id)->pluck('to_status')->all())->toBe(['REGISTERED', 'DOCUMENTS_SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'ACTIVE'])
        ->and(DB::table('audit_logs')->where('action', 'doctor.application_viewed')->where('is_access_event', true)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'doctor.credential_reviewed')->exists())->toBeTrue()
        ->and(DB::table('admin_activity_logs')->where('target_type', 'doctor_profile')->where('target_id', $doctor->id)->count())->toBeGreaterThanOrEqual(5)
        ->and(DB::table('admin_activity_logs')->where('target_id', $doctor->id)->where('path', 'like', '%/verify')->value('step_up_verified'))->toBeTrue()
        ->and(collect($this->messages->notices)->pluck('notice')->implode(' '))->toContain('verified')->toContain('now active');
});

it('needs a fresh step-up to verify or reject', function () {
    $doctor = submitted_doctor();
    [, $headers] = reviewer_session(stepUp: false);
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk();

    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/verify", ['reason' => 'Licence checked'], $headers)->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/reject", ['reason' => 'Licence photo is unreadable'], $headers)->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
});

it('rejects with a reason the doctor can see, and lets the doctor resubmit', function () {
    $doctor = submitted_doctor();
    [, $headers] = reviewer_session();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk();
    $credentialId = DoctorCredential::firstOrFail()->id;
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credentialId}/review", ['decision' => 'REJECTED'], $headers)->assertStatus(422)->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credentialId}/review", ['decision' => 'REJECTED', 'note' => 'The photo is blurred.'], $headers)->assertOk();

    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/reject", ['reason' => 'Upload a clearer PRC ID', 'more_documents' => true], $headers)->assertOk()->assertJsonPath('data.onboarding_status', 'REJECTED');
    app('auth')->forgetGuards();

    $doctorHeaders = bearer(native_login($doctor->user)->json('data.tokens.access_token'));
    $me = $this->getJson('/api/v1/doctors/me', $doctorHeaders)->assertOk()->json('data');
    expect(collect($me['verification_history'])->last()['reason'])->toBe('More documents needed: Upload a clearer PRC ID');
    $docs = $this->getJson('/api/v1/doctors/me/credentials', $doctorHeaders)->json('data');
    expect($docs[0]['status'])->toBe('REJECTED')->and($docs[0]['review_note'])->toBe('The photo is blurred.');

    // The rejected document may be replaced, then the application resubmitted.
    $this->deleteJson("/api/v1/doctors/me/credentials/{$credentialId}", [], $doctorHeaders)->assertOk();
    $this->post('/api/v1/doctors/me/credentials', ['credential_type' => 'PRC_ID', 'file' => UploadedFile::fake()->createWithContent('prc2.pdf', "%PDF-1.4\n%%EOF\n")], [...$doctorHeaders, 'Accept' => 'application/json'])->assertCreated();
    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $doctorHeaders)->assertOk()->assertJsonPath('data.onboarding_status', 'DOCUMENTS_SUBMITTED');
    expect(collect($this->messages->notices)->pluck('notice')->implode(' '))->toContain('needs changes')->not->toContain('clearer');
});

it('never lets a reviewer decide on their own profile', function () {
    [$reviewer, $headers] = reviewer_session();
    $profile = new DoctorProfile(['display_name' => 'Dr. Self Review']);
    $profile->user_id = $reviewer->id;
    $profile->save();
    DB::table('doctor_profiles')->where('id', $profile->id)->update(['onboarding_status' => 'DOCUMENTS_SUBMITTED']);

    $this->postJson("/api/v1/admin/doctors/{$profile->id}/start-review", [], $headers)->assertForbidden();
    $this->postJson("/api/v1/admin/doctors/{$profile->id}/verify", ['reason' => 'Licence checked'], $headers)->assertForbidden();
});

it('gives reviewers an audited signed link to clean documents only', function () {
    $doctor = submitted_doctor();
    [$reviewer, $headers] = reviewer_session();
    $credential = DoctorCredential::firstOrFail();

    $url = $this->getJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credential->id}/download-url", $headers)->assertOk()->json('data.url');
    $this->get($url)->assertOk();
    expect(DB::table('audit_logs')->where('action', 'doctor.credential_downloaded')->whereJsonContains('changes->viewer', $reviewer->id)->exists())->toBeTrue();

    $credential->forceFill(['scan_status' => 'QUARANTINED', 'scan_signature' => 'Test'])->save();
    $this->getJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credential->id}/download-url", $headers)->assertStatus(409);
    $this->get($url)->assertNotFound();
});

it("refuses to confirm another doctor's specialties", function () {
    $doctor = submitted_doctor();
    $other = submitted_doctor();
    [, $headers] = reviewer_session();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk();
    $foreign = $other->specialties()->firstOrFail()->id;

    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/specialties/confirm", ['specialty_ids' => [$foreign]], $headers)->assertStatus(422);
    expect($other->specialties()->firstOrFail()->verification_status)->toBe('SELF_REPORTED');
});

it('withdraws an accepted PRC ID when the doctor changes the licence number after a rejection', function () {
    $doctor = submitted_doctor();
    [, $headers] = reviewer_session();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk();
    $credentialId = DoctorCredential::firstOrFail()->id;
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credentialId}/review", ['decision' => 'ACCEPTED'], $headers)->assertOk();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/reject", ['reason' => 'Name does not match the register'], $headers)->assertOk();
    switch_user();

    $doctorHeaders = bearer(native_login($doctor->user)->json('data.tokens.access_token'));
    switch_user();
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '7654321'], $doctorHeaders)->assertOk();
    expect(DoctorCredential::findOrFail($credentialId)->status->value)->toBe('SUBMITTED');
    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $doctorHeaders)->assertOk();

    [, $headers] = reviewer_session();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/verify", ['reason' => 'Licence checked'], $headers)->assertStatus(422)->assertJsonValidationErrors('credentials');
});

it('does not count a service at a clinic where the doctor no longer practises (D-41)', function () {
    $doctor = submitted_doctor();
    [, $headers] = reviewer_session();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/start-review", [], $headers)->assertOk();
    $credentialId = DoctorCredential::firstOrFail()->id;
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/credentials/{$credentialId}/review", ['decision' => 'ACCEPTED'], $headers)->assertOk();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/verify", ['reason' => 'Licence checked'], $headers)->assertOk();

    // An in-person service at a location where the assignment has ended does not count.
    $org = new Organization;
    $org->forceFill(['name' => 'Test Group', 'organization_type' => 'CLINIC_GROUP'])->save();
    $clinic = new Clinic;
    $clinic->forceFill(['organization_id' => $org->id, 'name' => 'Test Clinic', 'slug' => 'test-clinic-'.Str::random(4)])->save();
    $location = new ClinicLocation;
    $location->forceFill(['clinic_id' => $clinic->id, 'name' => 'Main', 'address_line1' => 'Mabini St', 'city' => 'Makati'])->save();
    (new DoctorClinicAssignment)->forceFill(['doctor_profile_id' => $doctor->id, 'clinic_location_id' => $location->id, 'status' => 'ENDED', 'requested_by' => $doctor->user_id, 'effective_until' => now()])->save();
    (new DoctorService)->forceFill(['doctor_profile_id' => $doctor->id, 'clinic_location_id' => $location->id, 'name' => 'Consultation', 'consultation_type' => 'IN_PERSON', 'duration_minutes' => 20, 'fee_amount' => 65000, 'effective_from' => now(), 'created_by' => $doctor->user_id])->save();
    $this->postJson("/api/v1/admin/doctors/{$doctor->id}/activate", ['reason' => 'Ready to list'], $headers)->assertStatus(422)->assertJsonValidationErrors('services');
    expect($doctor->refresh()->onboarding_status->value)->toBe('VERIFIED');
});
