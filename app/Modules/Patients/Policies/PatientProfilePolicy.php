<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;

/**
 * Own-record scope (rbac-permissions.md, scope O). Guardian access to
 * dependents arrives with the dependents increment (2.2).
 */
final class PatientProfilePolicy
{
    public function view(User $user, PatientProfile $profile): bool
    {
        return $profile->user_id !== null && $profile->user_id === $user->id;
    }

    public function update(User $user, PatientProfile $profile): bool
    {
        return $this->view($user, $profile);
    }
}
