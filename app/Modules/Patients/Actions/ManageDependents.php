<?php

namespace App\Modules\Patients\Actions;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Audit\Contracts\RecordsConsents;
use App\Modules\Audit\Enums\ConsentType;
use App\Modules\Patients\Enums\GuardianAuthorization;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Shared\Exceptions\InvalidValue;
use Illuminate\Support\Facades\DB;

/**
 * Adding, changing and removing a guardian's dependents (CLAUDE.md §4.3).
 * The guardian attests their authority; that attestation is a consent record
 * the link cites. Removing a dependent revokes the link only: the dependent's
 * patient record (and any future clinical history) is never deleted.
 */
final class ManageDependents
{
    private const IDENTITY = ['first_name', 'middle_name', 'last_name', 'date_of_birth', 'sex'];

    public function __construct(
        private readonly RecordsConsents $consents,
        private readonly RecordsAudit $audit,
    ) {}

    /** @param  array{first_name: string, middle_name: string|null, last_name: string, date_of_birth: string, sex: string|null, relationship: string}  $person */
    public function add(PatientProfile $guardian, array $person): Dependent
    {
        return DB::transaction(function () use ($guardian, $person) {
            // Serialise concurrent adds by the same guardian so the limit holds.
            PatientProfile::whereKey($guardian->id)->lockForUpdate()->first();

            $max = (int) config('mediconnect.dependents.max_per_guardian');
            if (Dependent::authorized()->where('guardian_patient_profile_id', $guardian->id)->count() >= $max) {
                throw new InvalidValue("You can add up to {$max} family members.");
            }

            $profile = new PatientProfile(array_intersect_key($person, array_flip(self::IDENTITY)));
            $profile->save(); // no user_id: a dependent has no login of their own

            $consentId = $this->consents->record(
                (string) $guardian->user_id,
                ConsentType::GuardianAuthorization,
                (string) config('mediconnect.dependents.guardian_attestation_version'),
                true,
                $profile->id,
            );

            $link = new Dependent;
            $link->guardian_patient_profile_id = $guardian->id;
            $link->dependent_patient_profile_id = $profile->id;
            $link->forceFill([
                'relationship' => $person['relationship'],
                'authorization_status' => GuardianAuthorization::Authorized,
                'authorized_at' => now(),
                'consent_record_id' => $consentId,
            ])->save();

            $this->audit->record('dependent.added', 'patient_profile', $profile->id, ['relationship' => $person['relationship']]);

            return $link->load('dependent');
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function update(Dependent $link, array $changes): Dependent
    {
        return DB::transaction(function () use ($link, $changes) {
            $profile = $link->dependent;
            $profile->fill(array_intersect_key($changes, array_flip(self::IDENTITY)));
            $changed = array_keys($profile->getDirty());
            $profile->save();

            if (array_key_exists('relationship', $changes) && $changes['relationship'] !== $link->relationship->value) {
                $link->forceFill(['relationship' => $changes['relationship']])->save();
                $changed[] = 'relationship';
            }

            if ($changed !== []) {
                $this->audit->record('dependent.updated', 'patient_profile', $profile->id, ['fields' => $changed]);
            }

            return $link->refresh()->load('dependent');
        });
    }

    public function remove(Dependent $link, PatientProfile $guardian): void
    {
        DB::transaction(function () use ($link, $guardian) {
            $link->forceFill(['authorization_status' => GuardianAuthorization::Revoked, 'revoked_at' => now()])->save();

            // Withdrawal is a new consent row (consent_records is append-only).
            $this->consents->record(
                (string) $guardian->user_id,
                ConsentType::GuardianAuthorization,
                (string) config('mediconnect.dependents.guardian_attestation_version'),
                false,
                $link->dependent_patient_profile_id,
            );

            $this->audit->record('dependent.removed', 'patient_profile', $link->dependent_patient_profile_id);
        });
    }
}
