<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who a patient entry belongs to, always judged NOW from the patient profile and the guardian links (rule: self, or a guardian with an
 * AUTHORIZED link, PatientProfilePolicy). Having registered an entry earlier never keeps access: a revoked guardian loses the entry at once.
 */
final class PatientAccess
{
    public function owns(User $user, ?string $patientProfileId): bool
    {
        if ($patientProfileId === null) {
            return false;
        }
        $profile = PatientProfile::query()->find($patientProfileId);

        return $profile !== null && Gate::forUser($user)->allows('view', $profile);
    }

    /**
     * The profiles the user may act for now: their own, and those of dependants they are authorized for.
     *
     * @return list<string>
     */
    public function profileIds(User $user): array
    {
        $own = PatientProfile::query()->where('user_id', $user->id)->pluck('id')->all();
        $dependants = Dependent::authorized()->whereHas('guardian', fn ($q) => $q->where('user_id', $user->id))->pluck('dependent_patient_profile_id')->all();

        return array_values(array_unique(array_map('strval', [...$own, ...$dependants])));
    }

    /**
     * The accounts that may see and act on a profile's entries now: the profile's own account and its currently authorized guardians.
     * Notices about an entry go to these, never to a guardian who has since been revoked.
     *
     * @return list<string>
     */
    public function userIds(string $patientProfileId): array
    {
        $own = PatientProfile::query()->whereKey($patientProfileId)->value('user_id');
        $guardians = Dependent::authorized()->where('dependent_patient_profile_id', $patientProfileId)->with('guardian')->get()->map(fn (Dependent $d) => $d->guardian?->user_id);

        return array_values(array_unique(array_filter([$own, ...$guardians->all()], fn ($id) => is_string($id))));
    }
}
