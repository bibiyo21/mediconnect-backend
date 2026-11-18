<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;

/**
 * Own-record scope (rbac-permissions.md, scope O): the patient themself, or a
 * guardian with an AUTHORIZED dependents link.
 */
final class PatientProfilePolicy
{
    public function view(User $user, PatientProfile $profile): bool
    {
        return $this->isSelf($user, $profile) || $this->isAuthorizedGuardian($user, $profile);
    }

    public function update(User $user, PatientProfile $profile): bool
    {
        return $this->view($user, $profile);
    }

    private function isSelf(User $user, PatientProfile $profile): bool
    {
        return $profile->user_id !== null && $profile->user_id === $user->id;
    }

    private function isAuthorizedGuardian(User $user, PatientProfile $profile): bool
    {
        return Dependent::authorized()
            ->where('dependent_patient_profile_id', $profile->id)
            ->whereHas('guardian', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }
}
