<?php

use App\Modules\Audit\Contracts\RecordsConsents;
use App\Modules\Audit\Enums\ConsentType;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Rbac\Services\RoleAssignment;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
});

function request_deletion(User $user, array $headers, string $password = TEST_PASSWORD)
{
    return test()->postJson('/api/v1/auth/account/deletion-request', ['current_password' => $password], $headers);
}

it('schedules deletion 30 days out, signs out every device and tells the account holder', function () {
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(3, 0));
    $user = patient_account();
    $other = signed_in($user);
    $this->app['auth']->forgetGuards();
    $current = signed_in($user);

    request_deletion($user, $current)->assertStatus(202)->assertJsonPath('data.deletion_scheduled_for', '2026-12-18T03:00:00Z');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', $current)->assertStatus(401);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', $other)->assertStatus(401);
    expect($user->refresh()->deletion_requested_at)->not->toBeNull()
        ->and(collect($this->messages->notices)->pluck('notice')->implode(' '))->toContain('18 December 2026')
        ->and(DB::table('security_events')->where('event_type', 'ACCOUNT_DELETION_REQUESTED')->count())->toBe(1);
});

it('requires the current password', function () {
    $user = patient_account();

    request_deletion($user, signed_in($user), 'wrong-password-1')->assertStatus(422)->assertJsonValidationErrors('current_password', 'errors');
    expect($user->refresh()->deletion_requested_at)->toBeNull();
});

it('refuses self-service deletion for staff and clinician accounts', function () {
    $user = patient_account();
    app(RoleAssignment::class)->assign($user, Role::ClinicStaff);

    request_deletion($user, signed_in($user))->assertStatus(422)->assertJsonValidationErrors('account', 'errors');
    expect($user->refresh()->deletion_requested_at)->toBeNull();
});

it('cancels the request when the account holder signs in during the grace period', function () {
    $user = patient_account();
    request_deletion($user, signed_in($user))->assertStatus(202);
    $this->travel(10)->days();
    $this->app['auth']->forgetGuards();

    native_login($user)->assertOk();

    expect($user->refresh()->deletion_requested_at)->toBeNull()
        ->and(DB::table('security_events')->where('event_type', 'ACCOUNT_DELETION_CANCELLED')->count())->toBe(1)
        ->and(collect($this->messages->notices)->last()['notice'])->toContain('was cancelled');

    $this->travel(30)->days();
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();
    expect($user->refresh()->anonymized_at)->toBeNull();
});

it('anonymises the account, profiles, address and sole-guardian dependents after the grace period', function () {
    $user = patient_account();
    $headers = signed_in($user);
    $this->patchJson('/api/v1/patients/me', ['address' => ['line1' => '1 Rizal St', 'city' => 'Makati'], 'emergency_contact' => ['name' => 'Jose', 'phone' => '09171112222']], $headers)->assertOk();
    $child = $this->postJson('/api/v1/patients/me/dependents', [
        'first_name' => 'Lia', 'last_name' => 'Santos', 'date_of_birth' => '2018-03-10', 'relationship' => 'CHILD',
        'guardian_attestation' => true, 'guardian_attestation_version' => config('mediconnect.dependents.guardian_attestation_version'),
    ], [...$headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
    $email = $user->email;
    request_deletion($user, $headers)->assertStatus(202);

    $this->travel(29)->days();
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();
    expect($user->refresh()->anonymized_at)->toBeNull(); // still in the grace period

    $this->travel(2)->days();
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();

    $row = User::withTrashed()->findOrFail($user->id);
    $own = PatientProfile::withTrashed()->where('user_id', $user->id)->sole();
    $dependent = PatientProfile::withTrashed()->findOrFail($child);

    expect($row->anonymized_at)->not->toBeNull()
        ->and($row->trashed())->toBeTrue()
        ->and($row->status->value)->toBe('DEACTIVATED')
        ->and($row->email)->toBe("deleted-{$user->id}@anonymized.invalid")
        ->and($row->mobile_e164)->toBeNull()
        ->and($row->profile()->first()->first_name)->toBe('Deleted')
        ->and($row->profile()->first()->date_of_birth)->toBeNull()
        ->and(DB::table('user_addresses')->where('user_id', $user->id)->count())->toBe(0)
        ->and($own->first_name)->toBe('Deleted')->and($own->emergency_contact_phone)->toBeNull()->and($own->trashed())->toBeTrue()
        ->and($dependent->first_name)->toBe('Deleted')->and($dependent->date_of_birth)->toBeNull()
        ->and(Dependent::where('dependent_patient_profile_id', $child)->sole()->authorization_status->value)->toBe('REVOKED');

    // The old credentials no longer work, and nothing personal survives in the account row.
    $this->postJson('/api/v1/auth/login', ['identifier' => $email, 'password' => TEST_PASSWORD, 'device' => ['platform' => 'IOS']])->assertStatus(401);
    expect(json_encode(DB::table('users')->where('id', $user->id)->first()))->not->toContain($email);
    expect(DB::table('security_events')->where('event_type', 'ACCOUNT_ANONYMIZED')->count())->toBe(1);
});

it('keeps a dependent who still has another authorised guardian', function () {
    $leaving = patient_account();
    $staying = patient_account();
    $child = PatientProfile::factory()->create(['user_id' => null, 'first_name' => 'Lia']);
    foreach ([$leaving, $staying] as $guardian) {
        $consent = app(RecordsConsents::class)->record($guardian->id, ConsentType::GuardianAuthorization, 'draft-2026-11', true, $child->id);
        DB::table('dependents')->insert([
            'id' => (string) Str::uuid7(), 'guardian_patient_profile_id' => PatientProfile::where('user_id', $guardian->id)->value('id'),
            'dependent_patient_profile_id' => $child->id, 'relationship' => 'CHILD', 'authorization_status' => 'AUTHORIZED',
            'authorized_at' => now(), 'consent_record_id' => $consent, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    request_deletion($leaving, signed_in($leaving))->assertStatus(202);
    $this->travel(31)->days();
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();

    expect($child->refresh()->first_name)->toBe('Lia')->and($child->trashed())->toBeFalse()
        ->and(Dependent::authorized()->where('dependent_patient_profile_id', $child->id)->count())->toBe(1);
});

it('is idempotent: a second run changes nothing and a repeat request keeps the first date', function () {
    $user = patient_account();
    request_deletion($user, signed_in($user))->assertStatus(202);
    $first = $user->refresh()->deletion_requested_at;

    $this->travel(31)->days();
    $this->artisan('accounts:anonymize-deleted')->expectsOutputToContain('Anonymised 1 account(s).')->assertSuccessful();
    $this->artisan('accounts:anonymize-deleted')->expectsOutputToContain('Anonymised 0 account(s).')->assertSuccessful();

    expect(User::withTrashed()->find($user->id)->deletion_requested_at?->equalTo($first))->toBeTrue();
});

it('requires sign-in', function () {
    $this->postJson('/api/v1/auth/account/deletion-request', ['current_password' => 'x'])->assertStatus(401);
});
