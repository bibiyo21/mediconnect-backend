<?php

namespace App\Modules\Clinics\Contracts;

use App\Modules\Users\Models\User;

/**
 * Clinic-scoped authorization (rbac-permissions.md §1, scope AC; D-17).
 * A permission held at scope G (ADMIN, SUPER_ADMIN) applies to every clinic;
 * held at scope AC it applies only where the user has an ACTIVE membership.
 */
interface ClinicAccess
{
    public function allows(User $user, string $permission, string $clinicId): bool;

    /** @return list<string> ids of clinics where the user has an active membership */
    public function memberClinicIds(User $user): array;
}
