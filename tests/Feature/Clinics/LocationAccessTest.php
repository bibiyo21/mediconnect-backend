<?php

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Rbac\Enums\Role;
use Illuminate\Support\Str;

/*
 * Increment 5.2 (phase 5 plan Q14): ClinicAccess::allowsAtLocation() honours
 * clinic_members.clinic_location_id (null = every location of the clinic).
 */

beforeEach(function () {
    seed_roles();
    [$this->clinic, $this->locA, $manager] = clinic_with_manager('Santos Family Clinic');
    $this->locB = add_location($this->clinic, $manager, 'Branch B');
    [$this->otherClinic, $this->otherLoc] = clinic_with_manager('Diliman Medical Clinic');
    $this->access = app(ClinicAccess::class);
});

it('covers every location for a whole-clinic member and only that location for a restricted one', function () {
    [$all] = clinic_member($this->clinic, Role::ClinicStaff);
    [$onlyA] = clinic_member($this->clinic, Role::ClinicStaff, $this->locA);

    expect($this->access->allowsAtLocation($all, 'walkins.view', $this->locA))->toBeTrue()
        ->and($this->access->allowsAtLocation($all, 'walkins.view', $this->locB))->toBeTrue()
        ->and($this->access->allowsAtLocation($onlyA, 'walkins.view', $this->locA))->toBeTrue()
        ->and($this->access->allowsAtLocation($onlyA, 'walkins.view', $this->locB))->toBeFalse()
        // The clinic-wide question is unchanged: any active member passes.
        ->and($this->access->allows($onlyA, 'walkins.view', $this->clinic))->toBeTrue();
});

it('never reaches another clinic\'s location, a missing one or a permission the role lacks', function () {
    [$all] = clinic_member($this->clinic, Role::ClinicStaff);

    expect($this->access->allowsAtLocation($all, 'walkins.view', $this->otherLoc))->toBeFalse()
        ->and($this->access->allowsAtLocation($all, 'walkins.view', (string) Str::uuid7()))->toBeFalse()
        ->and($this->access->allowsAtLocation($all, 'clinics.update', $this->locA))->toBeFalse() // staff cannot update a clinic
        ->and($this->access->allowsAtLocation(account(Role::Patient), 'walkins.view', $this->locA))->toBeFalse();
});

it('ignores removed memberships and a location row that belongs to another clinic', function () {
    [$removed] = clinic_member($this->clinic, Role::ClinicStaff, null, 'REMOVED');
    expect($this->access->allowsAtLocation($removed, 'walkins.view', $this->locA))->toBeFalse();

    // Even if a member row pointed at another clinic's location, it cannot widen reach into this clinic.
    [$crossed] = clinic_member($this->clinic, Role::ClinicStaff, $this->otherLoc);
    expect($this->access->allowsAtLocation($crossed, 'walkins.view', $this->otherLoc))->toBeFalse()
        ->and($this->access->allowsAtLocation($crossed, 'walkins.view', $this->locA))->toBeFalse();
});

it('keeps global roles global', function () {
    expect($this->access->allowsAtLocation(account(Role::Admin), 'walkins.view', $this->locB))->toBeTrue()
        ->and($this->access->allowsAtLocation(account(Role::SupportAgent), 'walkins.view', $this->otherLoc))->toBeTrue()
        ->and($this->access->allowsAtLocation(account(Role::FinanceOfficer), 'walkins.view', $this->locA))->toBeFalse();
});

it('lists the allowed locations of a clinic', function () {
    [$all] = clinic_member($this->clinic, Role::ClinicStaff);
    [$onlyB] = clinic_member($this->clinic, Role::ClinicStaff, $this->locB);

    expect($this->access->allowedLocationIds($all, 'schedules.view', $this->clinic))->toEqualCanonicalizing([$this->locA, $this->locB])
        ->and($this->access->allowedLocationIds($onlyB, 'schedules.view', $this->clinic))->toBe([$this->locB])
        ->and($this->access->allowedLocationIds($onlyB, 'schedules.update', $this->clinic))->toBe([]) // staff cannot update schedules
        ->and($this->access->allowedLocationIds($onlyB, 'schedules.view', $this->otherClinic))->toBe([])
        ->and($this->access->allowedLocationIds(account(Role::Admin), 'schedules.view', $this->clinic))->toEqualCanonicalizing([$this->locA, $this->locB]);
});

it('answers false for a malformed location id instead of querying it', function () {
    [$all] = clinic_member($this->clinic, Role::ClinicStaff);

    expect($this->access->allowsAtLocation($all, 'walkins.view', 'not-a-uuid'))->toBeFalse()
        ->and($this->access->allowsAtLocation($all, 'walkins.view', ''))->toBeFalse()
        ->and($this->access->memberAllowsAtLocation($all, 'walkins.view', "x'; drop table users;--"))->toBeFalse();
});

it('adds up several restricted memberships in one clinic', function () {
    $user = account(Role::ClinicStaff);
    foreach ([$this->locA, $this->locB] as $location) {
        (new ClinicMember)->forceFill(['clinic_id' => $this->clinic, 'clinic_location_id' => $location, 'user_id' => $user->id, 'member_role' => 'CLINIC_STAFF', 'status' => 'ACTIVE', 'joined_at' => now()])->save();
    }
    $user->refresh();

    expect($this->access->allowedLocationIds($user, 'schedules.view', $this->clinic))->toEqualCanonicalizing([$this->locA, $this->locB])
        ->and($this->access->allowsAtLocation($user, 'walkins.view', $this->locA))->toBeTrue()
        ->and($this->access->allowsAtLocation($user, 'walkins.view', $this->locB))->toBeTrue()
        // Two restricted rows are still not clinic-wide access.
        ->and($this->access->allowsClinicWide($user, 'walkins.view', $this->clinic))->toBeFalse();
});

it('keeps clinic-wide access to unrestricted memberships and global roles', function () {
    [$all] = clinic_member($this->clinic, Role::ClinicManager);
    [$onlyA] = clinic_member($this->clinic, Role::ClinicManager, $this->locA);

    expect($this->access->allowsClinicWide($all, 'clinics.update', $this->clinic))->toBeTrue()
        ->and($this->access->allowsClinicWide($onlyA, 'clinics.update', $this->clinic))->toBeFalse()
        ->and($this->access->allows($onlyA, 'clinics.update', $this->clinic))->toBeTrue() // the member gate only
        ->and($this->access->allowsClinicWide($all, 'clinics.update', $this->otherClinic))->toBeFalse()
        ->and($this->access->allowsClinicWide(account(Role::Admin), 'clinics.update', $this->clinic))->toBeTrue()
        ->and($this->access->allowsClinicWide(account(Role::Patient), 'clinics.update', $this->clinic))->toBeFalse();
});

it('gives memberAllowsAtLocation no global shortcut', function () {
    [$onlyA] = clinic_member($this->clinic, Role::ClinicStaff, $this->locA);

    expect($this->access->memberAllowsAtLocation($onlyA, 'walkins.view', $this->locA))->toBeTrue()
        ->and($this->access->memberAllowsAtLocation($onlyA, 'walkins.view', $this->locB))->toBeFalse()
        ->and($this->access->memberAllowsAtLocation(account(Role::Admin), 'walkins.view', $this->locA))->toBeFalse()
        ->and($this->access->memberAllowsAtLocation(account(Role::SupportAgent), 'walkins.view', $this->locA))->toBeFalse();
});
