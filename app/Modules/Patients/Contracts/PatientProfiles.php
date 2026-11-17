<?php

namespace App\Modules\Patients\Contracts;

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;

interface PatientProfiles
{
    /**
     * @param  array{first_name: string, last_name: string, middle_name?: string|null, date_of_birth?: string|null, sex?: string|null}  $identity
     */
    public function createForAccount(User $user, array $identity): PatientProfile;
}
