<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Users\Models\User;

/**
 * Who may read or change a doctor's schedule at a place (rbac-permissions.md
 * `schedules.*`): the doctor themself (scope SD), or a member of the clinic
 * that owns the location and covers that location (scope AC, narrowed by
 * `clinic_members.clinic_location_id`; G holders through ClinicAccess). Online
 * schedules belong to the doctor alone.
 */
final class ScheduleAccess
{
    public function __construct(private readonly ClinicAccess $clinics) {}

    public function allows(User $user, string $permission, string $doctorProfileId, ?string $locationId): bool
    {
        if ($user->can($permission) && DoctorProfile::whereKey($doctorProfileId)->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Location-aware (Phase 5 plan Q14): a member restricted to one location has no reach into the others.
        return $locationId !== null && $this->clinics->allowsAtLocation($user, $permission, $locationId);
    }

    /** The clinic to record on audit entries when a clinic member acts. */
    public function clinicOf(?string $locationId): ?string
    {
        return $locationId === null ? null : ClinicLocation::whereKey($locationId)->value('clinic_id');
    }

    public function ownProfileId(User $user): ?string
    {
        return DoctorProfile::where('user_id', $user->id)->value('id');
    }
}
