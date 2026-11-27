<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Patients\Enums\GuardianAuthorization;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
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
     * The accounts that may see and act on each profile's entries now, for many profiles at once: two queries in total (the profiles' own
     * accounts, and the authorized guardians of all of them), never two per profile. Same rule as userIds().
     *
     * @param  list<string>  $patientProfileIds
     * @return array<string, list<string>> keyed by profile id; a profile with no account has an empty list
     */
    public function userIdsFor(array $patientProfileIds): array
    {
        $ids = array_values(array_unique(array_filter($patientProfileIds, 'is_string')));
        if ($ids === []) {
            return [];
        }
        $owners = PatientProfile::query()->whereIn('id', $ids)->pluck('user_id', 'id');
        // One join gives every authorized guardian's account of all these profiles (raw rows, not models: never a second query per profile).
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        /** @var list<object{profile_id: string, user_id: string|null}> $guardians */
        $guardians = DB::select(
            "select d.dependent_patient_profile_id as profile_id, g.user_id as user_id from dependents d
             join patient_profiles g on g.id = d.guardian_patient_profile_id
             where d.authorization_status = ? and g.deleted_at is null and d.dependent_patient_profile_id in ({$placeholders})",
            [GuardianAuthorization::Authorized->value, ...$ids],
        );

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
        }
        foreach ($owners as $id => $userId) {
            if (is_string($userId) && isset($out[(string) $id])) {
                $out[(string) $id][] = $userId;
            }
        }
        foreach ($guardians as $row) {
            if (is_string($row->user_id) && isset($out[(string) $row->profile_id])) {
                $out[(string) $row->profile_id][] = $row->user_id;
            }
        }
        foreach ($out as $id => $users) {
            $out[$id] = array_values(array_unique($users));
        }

        return $out;
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
