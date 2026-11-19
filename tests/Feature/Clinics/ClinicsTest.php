<?php

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

/** Headers for a fully signed-in user (MFA enrolled when the role needs it). */
function clinic_headers(User $user): array
{
    app('auth')->forgetGuards();
    $needsMfa = $user->getRoleNames()->intersect(['ADMIN', 'SUPER_ADMIN', 'MEDICAL_ADMIN', 'CLINIC_MANAGER', 'FINANCE_OFFICER'])->isNotEmpty();
    $headers = $needsMfa ? bearer(enroll_mfa($user)[2]) : signed_in($user);
    app('auth')->forgetGuards();

    return $headers;
}

function clinic_payload(array $overrides = []): array
{
    return array_merge([
        'organization' => ['name' => 'Santos Medical Group', 'organization_type' => 'CLINIC_GROUP'],
        'name' => 'Santos Family Clinic',
        'location' => ['name' => 'Makati branch', 'address_line1' => '12 Mabini St', 'city' => 'Makati', 'postal_code' => '1210', 'phone' => '+63 2 8123 4567'],
    ], $overrides);
}

/** A clinic created through the admin API; returns [clinicId, locationId]. */
function make_clinic(array $adminHeaders, array $overrides = []): array
{
    $data = test()->postJson('/api/v1/admin/clinics', clinic_payload($overrides), $adminHeaders)->assertCreated()->json('data');

    return [$data['id'], $data['locations'][0]['id'] ?? null];
}

function add_manager(string $clinicId, User $user): void
{
    app(AssignsRoles::class)->assign($user, Role::ClinicManager);
    $m = new ClinicMember;
    $m->forceFill(['clinic_id' => $clinicId, 'user_id' => $user->id, 'member_role' => 'CLINIC_MANAGER', 'status' => 'ACTIVE', 'joined_at' => now()])->save();
}

it('lets an ADMIN create a clinic with its organization and first location', function () {
    $admin = clinic_headers(account(Role::Admin));

    $response = $this->postJson('/api/v1/admin/clinics', clinic_payload(), $admin)->assertCreated()
        ->assertJsonPath('data.slug', 'santos-family-clinic')
        ->assertJsonPath('data.organization.name', 'Santos Medical Group')
        ->assertJsonPath('data.locations.0.city', 'Makati')
        ->assertJsonPath('data.locations.0.timezone', 'Asia/Manila')
        ->assertJsonPath('data.locations.0.phone', '+63281234567')
        ->assertJsonPath('data.abilities', ['update' => true, 'manage_hours' => true, 'manage_staff' => true]);

    $this->getJson('/api/v1/admin/clinics', $admin)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.location_count', 1);
    expect(DB::table('audit_logs')->where('action', 'clinic.created')->value('clinic_id'))->toBe($response->json('data.id'));
});

it('keeps phone numbers out of names (R-16) and validates locations', function (array $override, string $field) {
    $admin = clinic_headers(account(Role::Admin));
    $this->postJson('/api/v1/admin/clinics', clinic_payload($override), $admin)->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
})->with([
    'phone in clinic name' => [['name' => 'Santos Clinic call 0917 123 4567'], 'name'],
    'phone in location name' => [['location' => ['name' => 'Branch 8123-4567 ext', 'address_line1' => 'A', 'city' => 'B']], 'location.name'],
    'letters in phone' => [['location' => ['name' => 'Branch', 'address_line1' => 'A', 'city' => 'B', 'phone' => 'call me']], 'location.phone'],
    'bad timezone' => [['location' => ['name' => 'Branch', 'address_line1' => 'A', 'city' => 'B', 'timezone' => 'Mars/Base']], 'location.timezone'],
    'half coordinates' => [['location' => ['name' => 'Branch', 'address_line1' => 'A', 'city' => 'B', 'latitude' => 14.5]], 'location.longitude'],
    'both organization forms' => [['organization_id' => '01a1ec01-0000-7000-8000-000000000001'], 'organization_id'],
]);

it('does not let a clinic manager create clinics or list every clinic', function () {
    $headers = clinic_headers(tap(account(Role::Patient), fn ($u) => app(AssignsRoles::class)->assign($u, Role::ClinicManager)));
    $this->postJson('/api/v1/admin/clinics', clinic_payload(), $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/clinics', $headers)->assertStatus(403);
});

it('SECURITY: a clinic manager manages their own clinic but cannot reach an unrelated clinic', function () {
    [$mine, $myLocation] = make_clinic(clinic_headers(account(Role::Admin)));
    [$other, $otherLocation] = make_clinic(clinic_headers(account(Role::Admin)), ['name' => 'Other Clinic', 'organization' => ['name' => 'Other Group', 'organization_type' => 'SOLO_PRACTICE']]);
    $manager = account(Role::Patient);
    add_manager($mine, $manager);
    $headers = clinic_headers($manager->refresh());

    $this->getJson("/api/v1/clinics/{$mine}/management", $headers)->assertOk()->assertJsonPath('data.abilities.manage_staff', true);
    $this->patchJson("/api/v1/clinics/{$mine}", ['description' => 'Family care since 1990'], $headers)->assertOk();
    $this->getJson('/api/v1/me/clinics', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);

    $this->getJson("/api/v1/clinics/{$other}/management", $headers)->assertStatus(403);
    $this->patchJson("/api/v1/clinics/{$other}", ['name' => 'Taken over'], $headers)->assertStatus(403);
    $this->postJson("/api/v1/clinics/{$other}/locations", ['name' => 'X', 'address_line1' => 'A', 'city' => 'B'], $headers)->assertStatus(403);
    $this->putJson("/api/v1/clinics/{$other}/locations/{$otherLocation}/operating-hours", ['hours' => []], $headers)->assertStatus(403);
    $this->postJson("/api/v1/clinics/{$other}/members", ['email' => $manager->email, 'member_role' => 'CLINIC_MANAGER'], $headers)->assertStatus(403);
    // A location of another clinic cannot be reached through my clinic either.
    $this->putJson("/api/v1/clinics/{$mine}/locations/{$otherLocation}/operating-hours", ['hours' => []], $headers)->assertNotFound();

    expect(Clinic::find($other)->name)->toBe('Other Clinic');
});

it('gives clinic staff read access only', function () {
    [$clinic] = make_clinic(clinic_headers(account(Role::Admin)));
    $staff = account(Role::Patient);
    app(AssignsRoles::class)->assign($staff, Role::ClinicStaff);
    $m = new ClinicMember;
    $m->forceFill(['clinic_id' => $clinic, 'user_id' => $staff->id, 'member_role' => 'CLINIC_STAFF', 'status' => 'ACTIVE'])->save();
    $headers = clinic_headers($staff->refresh());

    $this->getJson("/api/v1/clinics/{$clinic}/management", $headers)->assertOk()->assertJsonPath('data.abilities', ['update' => false, 'manage_hours' => false, 'manage_staff' => false]);
    $this->patchJson("/api/v1/clinics/{$clinic}", ['description' => 'x'], $headers)->assertStatus(403);
});

it('replaces weekly hours atomically and rejects overlaps and reversed times', function () {
    $admin = clinic_headers(account(Role::Admin));
    [$clinic, $location] = make_clinic($admin);
    $url = "/api/v1/clinics/{$clinic}/locations/{$location}/operating-hours";

    $this->putJson($url, ['hours' => [
        ['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '12:00'],
        ['day_of_week' => 1, 'opens_at' => '13:00', 'closes_at' => '17:00'],
        ['day_of_week' => 6, 'opens_at' => '09:00', 'closes_at' => '12:00'],
    ]], $admin)->assertOk()->assertJsonCount(3, 'data.locations.0.operating_hours')->assertJsonPath('data.locations.0.operating_hours.1.opens_at', '13:00');

    $this->putJson($url, ['hours' => [
        ['day_of_week' => 2, 'opens_at' => '08:00', 'closes_at' => '12:00'],
        ['day_of_week' => 2, 'opens_at' => '11:00', 'closes_at' => '15:00'],
    ]], $admin)->assertStatus(422)->assertJsonValidationErrors('hours.1.opens_at', 'errors');
    $this->putJson($url, ['hours' => [['day_of_week' => 3, 'opens_at' => '17:00', 'closes_at' => '08:00']]], $admin)->assertStatus(422);
    $this->putJson($url, ['hours' => [['day_of_week' => 8, 'opens_at' => '08:00', 'closes_at' => '09:00']]], $admin)->assertStatus(422);

    expect(DB::table('clinic_operating_hours')->where('clinic_location_id', $location)->count())->toBe(3); // failed replacements changed nothing

    $this->putJson($url, ['hours' => []], $admin)->assertOk()->assertJsonCount(0, 'data.locations.0.operating_hours');
});

it('records closures and special-hours days', function () {
    $admin = clinic_headers(account(Role::Admin));
    [$clinic, $location] = make_clinic($admin);

    $this->postJson("/api/v1/clinics/{$clinic}/holidays", ['date' => '2026-12-25', 'name' => 'Christmas Day', 'is_closed' => true], $admin)->assertCreated();
    $id = $this->postJson("/api/v1/clinics/{$clinic}/holidays", ['date' => '2026-12-24', 'name' => 'Christmas Eve', 'is_closed' => false, 'special_opens_at' => '08:00', 'special_closes_at' => '12:00', 'clinic_location_id' => $location], $admin)
        ->assertCreated()->json('data.holidays.0.id');
    $this->postJson("/api/v1/clinics/{$clinic}/holidays", ['date' => '2026-12-31', 'name' => 'Short day', 'is_closed' => false], $admin)->assertStatus(422);

    $this->deleteJson("/api/v1/clinics/{$clinic}/holidays/{$id}", [], $admin)->assertOk()->assertJsonCount(1, 'data.holidays');
});

it('adds staff by existing account, assigns the role label, and removes it with the last membership', function () {
    $admin = clinic_headers(account(Role::Admin));
    [$clinic] = make_clinic($admin);
    $person = account(Role::Patient);

    $this->postJson("/api/v1/clinics/{$clinic}/members", ['email' => strtoupper($person->email), 'member_role' => 'CLINIC_STAFF'], $admin)
        ->assertCreated()->assertJsonPath('data.members.0.user_id', $person->id)->assertJsonPath('data.members.0.member_role', 'CLINIC_STAFF');
    expect($person->refresh()->hasRole('CLINIC_STAFF'))->toBeTrue();

    $this->postJson("/api/v1/clinics/{$clinic}/members", ['email' => $person->email, 'member_role' => 'CLINIC_STAFF'], $admin)->assertStatus(422);
    $this->postJson("/api/v1/clinics/{$clinic}/members", ['email' => 'nobody@example.test', 'member_role' => 'CLINIC_STAFF'], $admin)->assertStatus(422)->assertJsonValidationErrors('email', 'errors');

    $memberId = ClinicMember::where('user_id', $person->id)->value('id');
    $this->deleteJson("/api/v1/clinics/{$clinic}/members/{$memberId}", [], $admin)->assertOk()->assertJsonCount(0, 'data.members');
    expect($person->refresh()->hasRole('CLINIC_STAFF'))->toBeFalse()
        ->and(ClinicMember::find($memberId)->status)->toBe('REMOVED');
});

it('stops a manager removing themselves', function () {
    [$clinic] = make_clinic(clinic_headers(account(Role::Admin)));
    $manager = account(Role::Patient);
    add_manager($clinic, $manager);
    $headers = clinic_headers($manager->refresh());
    $memberId = ClinicMember::where('user_id', $manager->id)->value('id');

    $this->deleteJson("/api/v1/clinics/{$clinic}/members/{$memberId}", [], $headers)->assertStatus(422);
});

it('manages services per location and refuses duplicate names', function () {
    $admin = clinic_headers(account(Role::Admin));
    [$clinic, $location] = make_clinic($admin);
    $url = "/api/v1/clinics/{$clinic}/locations/{$location}/services";

    $service = $this->postJson($url, ['name' => 'Laboratory', 'default_duration_minutes' => 15], $admin)->assertCreated()->json('data.locations.0.services.0.id');
    $this->postJson($url, ['name' => 'laboratory'], $admin)->assertStatus(422);
    $this->postJson($url, ['name' => 'X-ray', 'default_duration_minutes' => 2], $admin)->assertStatus(422);
    $this->patchJson("{$url}/{$service}", ['status' => 'INACTIVE'], $admin)->assertOk()->assertJsonPath('data.locations.0.services.0.status', 'INACTIVE');
});

it('audits clinic changes against the clinic', function () {
    $admin = clinic_headers(account(Role::Admin));
    [$clinic, $location] = make_clinic($admin);
    $this->patchJson("/api/v1/clinics/{$clinic}/locations/{$location}", ['phone' => '0917 123 4567'], $admin)->assertOk()->assertJsonPath('data.locations.0.phone', '09171234567');

    expect(DB::table('audit_logs')->where('clinic_id', $clinic)->pluck('action')->all())->toBe(['clinic.created', 'clinic.location_updated']);
});
