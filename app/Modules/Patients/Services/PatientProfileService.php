<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Contracts\PatientProfiles;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;

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
}
