<?php

namespace App\Modules\Patients\Actions;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates the account holder's own patient profile. The person's name, birth
 * date and sex live on both patient_profiles (C-6) and user_profiles, so both
 * change together in one transaction.
 */
final class UpdateOwnPatientProfile
{
    private const IDENTITY = ['first_name', 'middle_name', 'last_name', 'date_of_birth', 'sex'];

    public function __construct(
        private readonly Accounts $accounts,
        private readonly RecordsAudit $audit,
    ) {}

    /** @param  array<string, mixed>  $changes  validated by UpdateMyPatientProfileRequest */
    public function handle(User $user, PatientProfile $profile, array $changes): PatientProfile
    {
        return DB::transaction(function () use ($user, $profile, $changes) {
            $identity = array_intersect_key($changes, array_flip(self::IDENTITY));
            $profile->fill($identity);

            if (array_key_exists('emergency_contact', $changes)) {
                $profile->emergency_contact_name = $changes['emergency_contact']['name'] ?? null;
                $profile->emergency_contact_phone = $changes['emergency_contact']['phone'] ?? null;
            }

            $changed = array_keys($profile->getDirty());
            $profile->save();

            $userFields = $identity;
            if (array_key_exists('preferred_language', $changes)) {
                $userFields['preferred_language'] = $changes['preferred_language'];
                $changed[] = 'preferred_language';
            }
            $this->accounts->updateProfile($user, $userFields);

            if (array_key_exists('address', $changes)) {
                $this->accounts->replacePrimaryAddress($user, $changes['address']);
                $changed[] = 'address';
            }

            if ($changed !== []) {
                // Field names only: the values are personal data and stay out of the audit trail.
                $this->audit->record('patient_profile.updated', 'patient_profile', $profile->id, ['fields' => array_values(array_unique($changed))]);
            }

            return $profile->refresh();
        });
    }
}
