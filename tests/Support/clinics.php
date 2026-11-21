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
    $privileged = $user->getRoleNames()->intersect(['ADMIN', 'SUPER_ADMIN', 'MEDICAL_ADMIN', 'CLINIC_MANAGER'])->isNotEmpty();
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
