<?php

use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SchedulingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.2 (review fixes H-1 and H-2): clinic management honours the location a
 * membership is restricted to. A manager restricted to location A cannot widen anyone's
 * reach (their own included), touch location B, or change the clinic itself; whole-clinic
 * members and global roles are unchanged.
 */

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinic, $this->locA, $this->manager] = clinic_with_manager();
    $this->locB = add_location($this->clinic, $this->manager, 'Branch B');
    [$this->restrictedUser, $this->restricted] = clinic_member($this->clinic, Role::ClinicManager, $this->locA);
    $this->admin = session_for(account(Role::Admin));
    app('auth')->forgetGuards();
});

/** The id of the first service listed under a location in a clinic management response. */
function service_at(TestResponse $response, string $locationId): string
{
    return (string) collect($response->json('data.locations'))->firstWhere('id', $locationId)['services'][0]['id'];
}

/** Fresh guard state for the next caller. */
function as_caller(array $headers): array
{
    app('auth')->forgetGuards();

    return $headers;
}

function membership_id(string $clinic, string $userId, ?string $location): string
{
    return (string) ClinicMember::where('clinic_id', $clinic)->where('user_id', $userId)->where('clinic_location_id', $location)->value('id');
}

it('stops a location-restricted manager adding clinic-wide staff, adding themselves, or adding at another location', function () {
    $person = patient_account();
    $base = ['email' => $person->email, 'member_role' => 'CLINIC_STAFF'];

    // Clinic-wide (no location) would widen reach beyond location A.
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", $base, as_caller($this->restricted))->assertForbidden();
    // Their own account: clinic-wide would be self-escalation, at A it is still their own membership.
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $this->restrictedUser->email, 'member_role' => 'CLINIC_MANAGER'], as_caller($this->restricted))->assertForbidden();
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $this->restrictedUser->email, 'member_role' => 'CLINIC_MANAGER', 'clinic_location_id' => $this->locB], as_caller($this->restricted))->assertForbidden();
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $this->restrictedUser->email, 'member_role' => 'CLINIC_STAFF', 'clinic_location_id' => $this->locA], as_caller($this->restricted))
        ->assertStatus(422)->assertJsonValidationErrors('member');
    // Another location.
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", [...$base, 'clinic_location_id' => $this->locB], as_caller($this->restricted))->assertForbidden();

    expect(ClinicMember::where('user_id', $person->id)->count())->toBe(0);

    // At their own location they can.
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", [...$base, 'clinic_location_id' => $this->locA], as_caller($this->restricted))->assertCreated();
    expect(ClinicMember::where('user_id', $person->id)->value('clinic_location_id'))->toBe($this->locA);
});

it('stops anyone adding or changing their own membership, even a whole-clinic manager or an admin', function () {
    $managerUser = ClinicMember::where('clinic_id', $this->clinic)->whereNull('clinic_location_id')->firstOrFail()->user;
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $managerUser->email, 'member_role' => 'CLINIC_STAFF', 'clinic_location_id' => $this->locB], as_caller($this->manager))
        ->assertStatus(422)->assertJsonValidationErrors('member');

    $adminUser = account(Role::Admin);
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $adminUser->email, 'member_role' => 'CLINIC_STAFF'], as_caller(session_for($adminUser)))
        ->assertStatus(422)->assertJsonValidationErrors('member');
});

it('stops a location-restricted manager removing staff at another location or clinic-wide staff', function () {
    [$staffB] = clinic_member($this->clinic, Role::ClinicStaff, $this->locB);
    [$staffAll] = clinic_member($this->clinic, Role::ClinicStaff);
    [$staffA] = clinic_member($this->clinic, Role::ClinicStaff, $this->locA);

    $this->deleteJson("/api/v1/clinics/{$this->clinic}/members/".membership_id($this->clinic, $staffB->id, $this->locB), [], as_caller($this->restricted))->assertForbidden();
    $this->deleteJson("/api/v1/clinics/{$this->clinic}/members/".membership_id($this->clinic, $staffAll->id, null), [], as_caller($this->restricted))->assertForbidden();
    expect(ClinicMember::whereIn('user_id', [$staffB->id, $staffAll->id])->where('status', 'ACTIVE')->count())->toBe(2);

    // Their own membership cannot be removed either; a colleague at their own location can.
    $this->deleteJson("/api/v1/clinics/{$this->clinic}/members/".membership_id($this->clinic, $this->restrictedUser->id, $this->locA), [], as_caller($this->restricted))->assertStatus(422);
    $this->deleteJson("/api/v1/clinics/{$this->clinic}/members/".membership_id($this->clinic, $staffA->id, $this->locA), [], as_caller($this->restricted))->assertOk();
    expect(ClinicMember::where('user_id', $staffA->id)->value('status'))->toBe('REMOVED');
});

it('lets a whole-clinic manager and an admin keep managing staff everywhere', function () {
    $person = patient_account();
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $person->email, 'member_role' => 'CLINIC_STAFF'], as_caller($this->manager))->assertCreated();
    $other = patient_account();
    $this->postJson("/api/v1/clinics/{$this->clinic}/members", ['email' => $other->email, 'member_role' => 'CLINIC_STAFF', 'clinic_location_id' => $this->locB], as_caller($this->admin))->assertCreated();
});

it('keeps clinic-level changes with clinic-wide access', function () {
    $this->patchJson("/api/v1/clinics/{$this->clinic}", ['name' => 'Renamed by A'], as_caller($this->restricted))->assertForbidden();
    $this->postJson("/api/v1/clinics/{$this->clinic}/locations", ['name' => 'Branch C', 'address_line1' => '1 Rizal St', 'city' => 'Pasig', 'postal_code' => '1600', 'phone' => '+63 2 8000 0000'], as_caller($this->restricted))->assertForbidden();
    $this->postJson("/api/v1/clinics/{$this->clinic}/holidays", ['date' => '2026-12-25', 'name' => 'Christmas Day', 'is_closed' => true], as_caller($this->restricted))->assertForbidden();

    $this->patchJson("/api/v1/clinics/{$this->clinic}", ['name' => 'Renamed by all'], as_caller($this->manager))->assertOk();
    expect(DB::table('clinics')->where('id', $this->clinic)->value('name'))->toBe('Renamed by all');
});

it('limits a restricted manager to their own location for location details, hours and services', function () {
    $base = "/api/v1/clinics/{$this->clinic}/locations";
    $hours = ['hours' => [['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '12:00']]];

    $this->patchJson("{$base}/{$this->locB}", ['phone' => '0917 123 4567'], as_caller($this->restricted))->assertForbidden();
    $this->putJson("{$base}/{$this->locB}/operating-hours", $hours, as_caller($this->restricted))->assertForbidden();
    $this->postJson("{$base}/{$this->locB}/services", ['name' => 'Laboratory'], as_caller($this->restricted))->assertForbidden();

    $this->patchJson("{$base}/{$this->locA}", ['phone' => '0917 123 4567'], as_caller($this->restricted))->assertOk();
    $this->putJson("{$base}/{$this->locA}/operating-hours", $hours, as_caller($this->restricted))->assertOk();
    $service = service_at($this->postJson("{$base}/{$this->locA}/services", ['name' => 'Laboratory'], as_caller($this->restricted))->assertCreated(), $this->locA);

    $serviceB = service_at($this->postJson("{$base}/{$this->locB}/services", ['name' => 'X-ray'], as_caller($this->manager))->assertCreated(), $this->locB);
    $this->patchJson("{$base}/{$this->locB}/services/{$serviceB}", ['status' => 'INACTIVE'], as_caller($this->restricted))->assertForbidden();
    $this->patchJson("{$base}/{$this->locA}/services/{$service}", ['status' => 'INACTIVE'], as_caller($this->restricted))->assertOk();

    expect(DB::table('clinic_operating_hours')->where('clinic_location_id', $this->locB)->count())->toBe(0);
});

it('limits holidays: a location one needs access there, a clinic-wide one needs clinic-wide access', function () {
    $closure = ['date' => '2026-12-25', 'name' => 'Christmas Day', 'is_closed' => true];

    $this->postJson("/api/v1/clinics/{$this->clinic}/holidays", [...$closure, 'clinic_location_id' => $this->locB], as_caller($this->restricted))->assertForbidden();
    $atA = $this->postJson("/api/v1/clinics/{$this->clinic}/holidays", [...$closure, 'clinic_location_id' => $this->locA], as_caller($this->restricted))->assertCreated()->json('data.holidays.0.id');

    $atB = $this->postJson("/api/v1/clinics/{$this->clinic}/holidays", [...$closure, 'date' => '2026-12-30', 'clinic_location_id' => $this->locB], as_caller($this->manager))->assertCreated()->json('data.holidays')[1]['id'];
    $wide = $this->postJson("/api/v1/clinics/{$this->clinic}/holidays", [...$closure, 'date' => '2026-12-31'], as_caller($this->manager))->assertCreated()->json('data.holidays')[2]['id'];

    $this->deleteJson("/api/v1/clinics/{$this->clinic}/holidays/{$atB}", [], as_caller($this->restricted))->assertForbidden();
    $this->deleteJson("/api/v1/clinics/{$this->clinic}/holidays/{$wide}", [], as_caller($this->restricted))->assertForbidden();
    $this->deleteJson("/api/v1/clinics/{$this->clinic}/holidays/{$atA}", [], as_caller($this->restricted))->assertOk();

    expect(DB::table('clinic_holidays')->whereIn('id', [$atB, $wide])->count())->toBe(2);
});

it('limits doctor assignments to the manager\'s locations', function () {
    [, $doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    [, $otherDoctorHeaders] = verified_doctor('7654321', 'ACTIVE');

    $this->postJson("/api/v1/clinics/{$this->clinic}/doctors", ['clinic_location_id' => $this->locB, 'prc_license_number' => '0123456'], as_caller($this->restricted))->assertForbidden();
    $this->postJson("/api/v1/clinics/{$this->clinic}/doctors", ['clinic_location_id' => $this->locA, 'prc_license_number' => '0123456'], as_caller($this->restricted))->assertCreated();

    // A doctor assigned at B by a whole-clinic manager is invisible to, and unmanageable by, the restricted manager.
    app('auth')->forgetGuards();
    $assignmentB = assign($this->clinic, $this->locB, $this->manager, $otherDoctorHeaders, '7654321');
    $listed = collect($this->getJson("/api/v1/clinics/{$this->clinic}/doctor-assignments", as_caller($this->restricted))->assertOk()->json('data'))->pluck('clinic_location_id')->unique()->all();
    expect($listed)->toBe([$this->locA]);
    $this->postJson("/api/v1/clinics/{$this->clinic}/doctor-assignments/{$assignmentB}/end", ['reason' => 'Moved'], as_caller($this->restricted))->assertForbidden();
    expect(DB::table('doctor_clinic_assignments')->where('id', $assignmentB)->value('status'))->toBe('ACTIVE');

    $all = collect($this->getJson("/api/v1/clinics/{$this->clinic}/doctor-assignments", as_caller($this->manager))->assertOk()->json('data'))->pluck('clinic_location_id')->unique()->sort()->values()->all();
    expect($all)->toBe(collect([$this->locA, $this->locB])->sort()->values()->all());
});

it('reports clinic-wide abilities, so a restricted manager is not offered clinic-level changes', function () {
    $this->getJson("/api/v1/clinics/{$this->clinic}/management", as_caller($this->restricted))->assertOk()
        ->assertJsonPath('data.abilities', ['update' => false, 'manage_hours' => false, 'manage_staff' => false, 'manage_doctors' => false]);
    $this->getJson("/api/v1/clinics/{$this->clinic}/management", as_caller($this->manager))->assertOk()
        ->assertJsonPath('data.abilities', ['update' => true, 'manage_hours' => true, 'manage_staff' => true, 'manage_doctors' => true]);
});

it('leaves a global admin able to act at every location', function () {
    $base = "/api/v1/clinics/{$this->clinic}/locations";
    $this->patchJson("{$base}/{$this->locB}", ['phone' => '0917 123 4567'], as_caller($this->admin))->assertOk();
    $this->postJson("/api/v1/clinics/{$this->clinic}/holidays", ['date' => '2026-12-25', 'name' => 'Christmas Day', 'is_closed' => true], as_caller($this->admin))->assertCreated();
});
