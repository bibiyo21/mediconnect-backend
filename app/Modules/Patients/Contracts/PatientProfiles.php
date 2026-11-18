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

    /**
     * Account deletion (D-38): anonymises the account holder's own patient
     * profile, revokes their guardian links, and anonymises dependents who have
     * no other authorised guardian. Rows are kept for referential integrity.
     * Clinical records (Phase 9) will need retention rules here (D-23).
     */
    public function anonymizeForAccount(User $user): void;
}
