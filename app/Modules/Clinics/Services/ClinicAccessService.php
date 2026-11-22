<?php

namespace App\Modules\Clinics\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class ClinicAccessService implements ClinicAccess
{
    /** Roles whose clinic permissions are global (scope G in the matrix). */
    private const GLOBAL_ROLES = ['SUPER_ADMIN', 'ADMIN', 'MEDICAL_ADMIN', 'FINANCE_OFFICER', 'SUPPORT_AGENT'];

    /** Roles whose clinic permissions only apply to clinics they belong to (scope AC). */
    private const CLINIC_ROLES = ['CLINIC_MANAGER', 'CLINIC_STAFF'];

    public function allows(User $user, string $permission, string $clinicId): bool
    {
        return $this->grants($user, $permission, $clinicId, null, false, true);
    }

    public function allowsAtLocation(User $user, string $permission, string $locationId): bool
    {
        $clinicId = $this->clinicOfLocation($locationId);

        return $clinicId !== null && $this->grants($user, $permission, $clinicId, $locationId, false, true);
    }

    public function allowsClinicWide(User $user, string $permission, string $clinicId): bool
    {
        return Str::isUuid($clinicId) && $this->grants($user, $permission, $clinicId, null, true, true);
    }

    public function memberAllowsAtLocation(User $user, string $permission, string $locationId): bool
    {
        $clinicId = $this->clinicOfLocation($locationId);

        return $clinicId !== null && $this->grants($user, $permission, $clinicId, $locationId, false, false);
    }

    private function clinicOfLocation(string $locationId): ?string
    {
        if (! Str::isUuid($locationId)) {
            return null;
        }
        $clinicId = ClinicLocation::whereKey($locationId)->value('clinic_id');

        return is_string($clinicId) ? $clinicId : null;
    }

    public function allowedLocationIds(User $user, string $permission, string $clinicId): array
    {
        $all = array_values(ClinicLocation::where('clinic_id', $clinicId)->pluck('id')->all());

        if (! $user->can($permission)) {
            return [];
        }
        if ($this->hasGlobalGrant($user, $permission)) {
            return $all;
        }

        $allowed = [];
        foreach ($this->activeMemberships($user, $clinicId) as $member) {
            if (! $this->memberGrants($user, $member->member_role, $permission)) {
                continue;
            }
            if ($member->clinic_location_id === null) {
                return $all;
            }
            $allowed[] = $member->clinic_location_id;
        }

        return array_values(array_intersect($all, $allowed));
    }

    /**
     * $locationId null and $clinicWide false = the clinic-level question (any active member).
     * $locationId set = the membership must cover it (null member location = all of them).
     * $clinicWide = the membership must be unrestricted. $allowGlobal false skips global roles.
     */
    private function grants(User $user, string $permission, string $clinicId, ?string $locationId, bool $clinicWide, bool $allowGlobal): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($allowGlobal && $this->hasGlobalGrant($user, $permission)) {
            return true;
        }

        foreach ($this->activeMemberships($user, $clinicId) as $member) {
            $covers = match (true) {
                $clinicWide => $member->clinic_location_id === null,
                $locationId !== null => $member->clinic_location_id === null || $member->clinic_location_id === $locationId,
                default => true,
            };
            if ($covers && $this->memberGrants($user, $member->member_role, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A global grant applies everywhere. The permission is checked against the
     * global roles only, so a clinic role cannot widen a global user's reach.
     */
    private function hasGlobalGrant(User $user, string $permission): bool
    {
        foreach (self::GLOBAL_ROLES as $role) {
            if ($user->hasRole($role) && $this->roleGrants($role, $permission, 'G')) {
                return true;
            }
        }

        return false;
    }

    private function memberGrants(User $user, string $memberRole, string $permission): bool
    {
        return in_array($memberRole, self::CLINIC_ROLES, true) && $user->hasRole($memberRole) && $this->roleGrants($memberRole, $permission, 'AC');
    }

    /** @return Collection<int, ClinicMember> */
    private function activeMemberships(User $user, string $clinicId): Collection
    {
        return ClinicMember::where('user_id', $user->id)->where('clinic_id', $clinicId)->where('status', 'ACTIVE')->get();
    }

    public function memberClinicIds(User $user): array
    {
        return array_values(ClinicMember::where('user_id', $user->id)->where('status', 'ACTIVE')->distinct()->pluck('clinic_id')->all());
    }

    /** Whether the matrix grants $permission to $role at exactly $scope (config/rbac.php). */
    private function roleGrants(string $role, string $permission, string $scope): bool
    {
        if ($role === 'SUPER_ADMIN') {
            return ! in_array($permission, (array) config('rbac.super_admin_excluded'), true);
        }

        $grants = (array) config("rbac.grants.{$role}", []);

        return ($grants[$permission] ?? null) === $scope;
    }
}
