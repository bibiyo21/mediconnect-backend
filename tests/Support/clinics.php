<?php

use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Shared\Services\Fingerprint;
use Illuminate\Support\Facades\DB;

/*
 * Clinic and doctor fixtures through the real APIs (moved from
 * DoctorServicesTest in 4.2 so scheduling tests can share them).
 */

/** Headers for a fresh session (MFA for privileged roles), with the test client's cached user cleared. */
function session_for(User $user): array
{
    app('auth')->forgetGuards();
    $privileged = $user->getRoleNames()->contains(fn (string $role) => Role::from($role)->requiresMfa());
    $headers = $privileged ? bearer(enroll_mfa($user)[2]) : signed_in($user);
    app('auth')->forgetGuards();

    return $headers;
}

/** A clinic with one location and a manager; returns [clinicId, locationId, managerHeaders]. */
function clinic_with_manager(string $name = 'Santos Family Clinic'): array
{
    $admin = session_for(account(Role::Admin));
    $data = test()->postJson('/api/v1/admin/clinics', [
        'organization' => ['name' => $name.' Group', 'organization_type' => 'CLINIC_GROUP'],
        'name' => $name,
        'location' => ['name' => 'Main branch', 'address_line1' => '12 Mabini St', 'city' => 'Makati', 'postal_code' => '1210', 'phone' => '+63 2 8123 4567'],
    ], $admin)->assertCreated()->json('data');
    $manager = account();
    app(AssignsRoles::class)->assign($manager, Role::ClinicManager);
    (new ClinicMember)->forceFill(['clinic_id' => $data['id'], 'user_id' => $manager->id, 'member_role' => 'CLINIC_MANAGER', 'status' => 'ACTIVE', 'joined_at' => now()])->save();

    return [$data['id'], $data['locations'][0]['id'], session_for($manager->refresh())];
}

/** A verified doctor with a licence number; returns [profile, headers]. */
function verified_doctor(string $licence = '0123456', string $status = 'VERIFIED'): array
{
    $user = patient_account();
    app(AssignsRoles::class)->assign($user, Role::Doctor);
    $profile = new DoctorProfile(['display_name' => 'Dr. Ana Santos']);
    $profile->user_id = $user->id;
    $profile->prc_license_number_encrypted = $licence;
    $profile->prc_license_number_hash = Fingerprint::of('prc:'.$licence);
    $profile->save();
    DB::table('doctor_profiles')->where('id', $profile->id)->update(['onboarding_status' => $status, 'license_verification_status' => in_array($status, ['VERIFIED', 'ACTIVE'], true) ? 'VERIFIED' : 'NOT_VERIFIED', 'verified_at' => in_array($status, ['VERIFIED', 'ACTIVE'], true) ? now() : null]);

    return [$profile->refresh(), session_for($user)];
}

/** Invites and accepts; returns the assignment id. */
function assign(string $clinicId, string $locationId, array $managerHeaders, array $doctorHeaders, string $licence = '0123456'): string
{
    $id = test()->postJson("/api/v1/clinics/{$clinicId}/doctors", ['clinic_location_id' => $locationId, 'prc_license_number' => $licence], $managerHeaders)->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    test()->postJson("/api/v1/doctors/me/assignments/{$id}/accept", [], $doctorHeaders)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    app('auth')->forgetGuards();

    return $id;
}

function in_person(string $locationId, int $fee, string $name = 'Consultation'): array
{
    return ['consultation_type' => 'IN_PERSON', 'clinic_location_id' => $locationId, 'name' => $name, 'duration_minutes' => 20, 'buffer_minutes' => 5, 'fee_amount' => $fee];
}

/** A second (or further) location for a clinic, through the manager's API; returns its id. */
function add_location(string $clinicId, array $managerHeaders, string $name = 'Second branch'): string
{
    $clinic = test()->postJson("/api/v1/clinics/{$clinicId}/locations", ['name' => $name, 'address_line1' => '45 Rizal Ave', 'city' => 'Quezon City', 'postal_code' => '1100', 'phone' => '+63 2 8765 4321'], $managerHeaders)->assertCreated()->json('data');
    app('auth')->forgetGuards();

    return (string) collect($clinic['locations'])->firstWhere('name', $name)['id'];
}

/**
 * A clinic member with the given role: all locations of the clinic when $locationId is null,
 * otherwise only that location (clinic_members.clinic_location_id, database-schema.md §4).
 *
 * @return array{0: User, 1: array<string, string>} the user and fresh session headers
 */
function clinic_member(string $clinicId, Role $role, ?string $locationId = null, string $status = 'ACTIVE'): array
{
    $user = account($role);
    $row = (new ClinicMember)->forceFill([
        'clinic_id' => $clinicId, 'clinic_location_id' => $locationId, 'user_id' => $user->id,
        'member_role' => $role === Role::ClinicManager ? 'CLINIC_MANAGER' : 'CLINIC_STAFF', 'status' => $status,
        'joined_at' => now(), 'removed_at' => $status === 'REMOVED' ? now() : null,
    ]);
    $row->save();

    return [$user->refresh(), session_for($user->refresh())];
}
