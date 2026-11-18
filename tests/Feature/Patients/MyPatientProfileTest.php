<?php

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

it('shows the signed-in patient their own profile', function () {
    $user = patient_account();

    $this->getJson('/api/v1/patients/me', signed_in($user))
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Ana')
        ->assertJsonPath('data.date_of_birth', '1990-05-01')
        ->assertJsonPath('data.preferred_language', 'en')
        ->assertJsonPath('data.emergency_contact', null)
        ->assertJsonPath('data.address', null);
});

it('requires sign-in', function () {
    $this->getJson('/api/v1/patients/me')->assertStatus(401);
    $this->patchJson('/api/v1/patients/me', ['first_name' => 'X'])->assertStatus(401);
});

it('returns 404 for an account without a patient profile', function () {
    $this->getJson('/api/v1/patients/me', signed_in(account(Role::ClinicStaff)))->assertStatus(404);
});

it('updates only the fields sent and keeps user_profiles in sync', function () {
    $user = patient_account();

    $this->patchJson('/api/v1/patients/me', [
        'first_name' => '  Maria ',
        'sex' => 'FEMALE',
        'preferred_language' => 'fil',
        'emergency_contact' => ['name' => 'Jose Santos', 'phone' => '0917 765 4321'],
        'address' => ['line1' => '12 Mabini St', 'barangay' => 'Poblacion', 'city' => 'Makati', 'postal_code' => '1210'],
    ], signed_in($user))
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Maria')
        ->assertJsonPath('data.last_name', 'Santos')
        ->assertJsonPath('data.sex', 'FEMALE')
        ->assertJsonPath('data.preferred_language', 'fil')
        ->assertJsonPath('data.emergency_contact', ['name' => 'Jose Santos', 'phone' => '+639177654321'])
        ->assertJsonPath('data.address.city', 'Makati')
        ->assertJsonPath('data.address.country_code', 'PH');

    $user->refresh();
    expect($user->profile->first_name)->toBe('Maria')
        ->and($user->profile->preferred_language)->toBe('fil')
        ->and($user->addresses()->where('is_primary', true)->count())->toBe(1);
});

it('replaces the primary address instead of adding another, and removes it with null', function () {
    $user = patient_account();
    $headers = signed_in($user);

    $this->patchJson('/api/v1/patients/me', ['address' => ['line1' => 'A', 'city' => 'Pasig']], $headers)->assertOk();
    $this->patchJson('/api/v1/patients/me', ['address' => ['line1' => 'B', 'city' => 'Taguig']], $headers)->assertOk()->assertJsonPath('data.address.line1', 'B');
    expect($user->addresses()->count())->toBe(1);

    $this->patchJson('/api/v1/patients/me', ['address' => null], $headers)->assertOk()->assertJsonPath('data.address', null);
    expect($user->addresses()->count())->toBe(0);
});

it('refuses a birth date that would make the account holder a minor (D-37)', function () {
    $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 18)->setTime(9, 0));
    $user = patient_account();

    $this->patchJson('/api/v1/patients/me', ['date_of_birth' => '2008-11-19'], signed_in($user))
        ->assertStatus(422)->assertJsonValidationErrors('date_of_birth', 'errors');
    $this->patchJson('/api/v1/patients/me', ['date_of_birth' => '2008-11-18'], signed_in($user))
        ->assertOk()->assertJsonPath('data.date_of_birth', '2008-11-18');
});

it('validates names, phone, postal code and unknown keys', function (array $body, string $field) {
    $this->patchJson('/api/v1/patients/me', $body, signed_in(patient_account()))
        ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
})->with([
    'empty first name' => [['first_name' => ''], 'first_name'],
    'clearing birth date' => [['date_of_birth' => null], 'date_of_birth'],
    'landline as emergency phone' => [['emergency_contact' => ['name' => 'Jose', 'phone' => '0281234567']], 'emergency_contact.phone'],
    'address without city' => [['address' => ['line1' => 'A']], 'address.city'],
    'bad postal code' => [['address' => ['line1' => 'A', 'city' => 'B', 'postal_code' => '12']], 'address.postal_code'],
    'unexpected address key' => [['address' => ['line1' => 'A', 'city' => 'B', 'country_code' => 'US']], 'address'],
    'unknown language' => [['preferred_language' => 'es'], 'preferred_language'],
]);

it('ignores attempts to change fields that are not editable here', function () {
    $user = patient_account();
    $profile = PatientProfile::where('user_id', $user->id)->first();

    $this->patchJson('/api/v1/patients/me', ['status' => 'INACTIVE', 'user_id' => (string) str()->uuid(), 'id' => 'x'], signed_in($user))->assertOk();

    expect($profile->refresh()->status->value)->toBe('ACTIVE')->and($profile->user_id)->toBe($user->id);
});

it('only ever touches the caller\'s own profile (no IDOR through the body)', function () {
    $ana = patient_account();
    $other = patient_account();
    $otherProfile = PatientProfile::where('user_id', $other->id)->first();

    $this->patchJson('/api/v1/patients/me', ['id' => $otherProfile->id, 'first_name' => 'Hacked'], signed_in($ana))->assertOk();

    expect($otherProfile->refresh()->first_name)->toBe('Ana');
});

it('audits which fields changed, without the values', function () {
    $user = patient_account();

    $this->patchJson('/api/v1/patients/me', ['last_name' => 'Reyes', 'emergency_contact' => ['name' => 'Jose', 'phone' => '09177654321']], signed_in($user))->assertOk();

    $row = DB::table('audit_logs')->where('action', 'patient_profile.updated')->sole();
    expect($row->actor_user_id)->toBe($user->id)
        ->and(json_decode($row->changes, true))->toBe(['fields' => ['last_name', 'emergency_contact_name', 'emergency_contact_phone']])
        ->and($row->changes)->not->toContain('Reyes')->not->toContain('639177654321');
});

it('records no audit entry when nothing changed', function () {
    $user = patient_account();
    $this->patchJson('/api/v1/patients/me', ['first_name' => 'Ana'], signed_in($user))->assertOk();

    expect(DB::table('audit_logs')->where('action', 'patient_profile.updated')->count())->toBe(0);
});
