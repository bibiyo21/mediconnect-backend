<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Contracts\PatientProfiles;
use App\Modules\Patients\Enums\GuardianAuthorization;
use App\Modules\Patients\Enums\PatientStatus;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

final class PatientProfileService implements PatientProfiles
{
    public function createForAccount(User $user, array $identity): PatientProfile
    {
        $patient = new PatientProfile([
            'first_name' => $identity['first_name'],
            'middle_name' => $identity['middle_name'] ?? null,
            'last_name' => $identity['last_name'],
            'date_of_birth' => $identity['date_of_birth'] ?? null,
            'sex' => $identity['sex'] ?? null,
        ]);
        $patient->user()->associate($user);
        $patient->save();

        return $patient;
    }

    public function anonymizeForAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $own = PatientProfile::where('user_id', $user->id)->first();
            if ($own === null) {
                return;
            }

            $links = Dependent::where('guardian_patient_profile_id', $own->id)->where('authorization_status', '!=', GuardianAuthorization::Revoked->value)->get();
            foreach ($links as $link) {
                $link->forceFill(['authorization_status' => GuardianAuthorization::Revoked, 'revoked_at' => now()])->save();

                $otherGuardians = Dependent::authorized()->where('dependent_patient_profile_id', $link->dependent_patient_profile_id)->exists();
                if (! $otherGuardians) {
                    $this->anonymizeProfile(PatientProfile::findOrFail($link->dependent_patient_profile_id));
                }
            }

            $this->anonymizeProfile($own);
        });
    }

    private function anonymizeProfile(PatientProfile $profile): void
    {
        $profile->forceFill([
            'first_name' => 'Deleted', 'middle_name' => null, 'last_name' => 'Patient',
            'date_of_birth' => null, 'sex' => null,
            'emergency_contact_name' => null, 'emergency_contact_phone' => null,
            'status' => PatientStatus::Inactive,
        ])->save();
        $profile->delete(); // soft delete
    }
}
