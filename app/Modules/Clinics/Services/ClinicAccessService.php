<?php

namespace App\Modules\Clinics\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Users\Models\User;

final class ClinicAccessService implements ClinicAccess
{
    /** Roles whose clinic permissions are global (scope G in the matrix). */
    private const GLOBAL_ROLES = ['SUPER_ADMIN', 'ADMIN', 'MEDICAL_ADMIN', 'FINANCE_OFFICER', 'SUPPORT_AGENT'];

    /** Roles whose clinic permissions only apply to clinics they belong to (scope AC). */
    private const CLINIC_ROLES = ['CLINIC_MANAGER', 'CLINIC_STAFF'];

    public function allows(User $user, string $permission, string $clinicId): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        // A global grant applies everywhere. The permission is checked against the
        // global roles only, so a clinic role cannot widen a global user's reach.
        foreach (self::GLOBAL_ROLES as $role) {
            if ($user->hasRole($role) && $this->roleGrants($role, $permission, 'G')) {
                return true;
            }
        }

        $memberRoles = ClinicMember::where('user_id', $user->id)->where('clinic_id', $clinicId)->where('status', 'ACTIVE')->pluck('member_role')->all();

        foreach ($memberRoles as $role) {
            if (in_array($role, self::CLINIC_ROLES, true) && $user->hasRole($role) && $this->roleGrants($role, $permission, 'AC')) {
                return true;
            }
        }

        return false;
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
