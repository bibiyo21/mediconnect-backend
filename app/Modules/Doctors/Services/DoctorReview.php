<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Enums\CredentialStatus;
use App\Modules\Doctors\Enums\CredentialType;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Exceptions\CredentialNotAvailable;
use App\Modules\Doctors\Exceptions\InvalidOnboardingTransition;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Users\Models\User;
use App\Shared\Enums\DeliveryChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reviewer decisions on a doctor's application (increment 2.9, CLAUDE.md §5.2).
 * Every state change goes through DoctorOnboarding (row lock, explicit
 * transitions, append-only history). Notices to the doctor are generic: the
 * detail is in the app, never in an email or SMS.
 */
final class DoctorReview
{
    public function __construct(
        private readonly DoctorOnboarding $onboarding,
        private readonly RecordsAudit $audit,
        private readonly SendsOneTimeMessages $messages,
    ) {}

    public function startReview(DoctorProfile $profile, User $reviewer): DoctorProfile
    {
        return $this->onboarding->transition($profile, OnboardingStatus::UnderReview, $reviewer->id);
    }

    public function decideCredential(DoctorProfile $profile, DoctorCredential $credential, CredentialStatus $decision, ?string $note, User $reviewer): DoctorCredential
    {
        return DB::transaction(function () use ($profile, $credential, $decision, $note, $reviewer) {
            /** @var DoctorProfile $locked */
            $locked = DoctorProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($locked->onboarding_status !== OnboardingStatus::UnderReview) {
                throw InvalidOnboardingTransition::between($locked->onboarding_status, OnboardingStatus::UnderReview);
            }
            if (! $credential->downloadable()) {
                throw CredentialNotAvailable::notScanned();
            }
            $credential->forceFill(['status' => $decision, 'review_note' => $note, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
            $this->audit->record('doctor.credential_reviewed', 'doctor_credential', $credential->id, ['decision' => $decision->value]);

            return $credential;
        });
    }

    /**
     * Confirms self-reported specialty entries (D-40) once the reviewer has
     * checked them against the documents.
     *
     * @param  list<string>  $specialtyEntryIds  doctor_specialties ids
     */
    public function confirmSpecialties(DoctorProfile $profile, array $specialtyEntryIds, User $reviewer): int
    {
        if (! in_array($profile->onboarding_status, [OnboardingStatus::UnderReview, OnboardingStatus::Verified, OnboardingStatus::Active], true)) {
            throw InvalidOnboardingTransition::between($profile->onboarding_status, OnboardingStatus::UnderReview);
        }
        $entries = DoctorSpecialty::where('doctor_profile_id', $profile->id)->whereIn('id', $specialtyEntryIds)->get();
        if ($entries->count() !== count(array_unique($specialtyEntryIds))) {
            throw ValidationException::withMessages(['specialty_ids' => ['One or more specialties do not belong to this doctor.']]);
        }
        $count = DoctorSpecialty::whereIn('id', $entries->modelKeys())->where('verification_status', 'SELF_REPORTED')
            ->update(['verification_status' => 'CONFIRMED', 'confirmed_by' => $reviewer->id, 'confirmed_at' => now()]);
        $this->audit->record('doctor.specialties_confirmed', 'doctor_profile', $profile->id, ['count' => $count]);

        return $count;
    }

    public function verify(DoctorProfile $profile, User $reviewer, ?string $reason): DoctorProfile
    {
        $errors = [];
        if ($profile->prc_license_number_hash === null) {
            $errors['prc_license_number'] = ['The doctor has not given a licence number.'];
        }
        $acceptedPrcId = DoctorCredential::where('doctor_profile_id', $profile->id)
            ->where('credential_type', CredentialType::PrcId->value)->where('status', CredentialStatus::Accepted->value)->exists();
        if (! $acceptedPrcId) {
            $errors['credentials'] = ['Accept the PRC ID document before verifying.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $profile = $this->onboarding->transition($profile, OnboardingStatus::Verified, $reviewer->id, $reason);
        $this->notify($profile, 'Your MediConnect doctor profile has been verified. Open the app to see what happens next.');

        return $profile;
    }

    public function reject(DoctorProfile $profile, User $reviewer, string $reason, bool $moreDocuments): DoctorProfile
    {
        $text = ($moreDocuments ? 'More documents needed: ' : '').$reason;
        $profile = $this->onboarding->transition($profile, OnboardingStatus::Rejected, $reviewer->id, $text);
        $this->notify($profile, 'Your MediConnect doctor application needs changes. Open the app to see what to update.');

        return $profile;
    }

    public function activate(DoctorProfile $profile, User $reviewer, ?string $reason): DoctorProfile
    {
        $profile = $this->onboarding->transition($profile, OnboardingStatus::Active, $reviewer->id, $reason);
        $this->notify($profile, 'Your MediConnect doctor profile is now active.');

        return $profile;
    }

    private function notify(DoctorProfile $profile, string $notice): void
    {
        $user = $profile->user;
        if ($user->email !== null) {
            $this->messages->sendNotice(DeliveryChannel::Email, $user->email, $notice);
        } elseif ($user->mobile_e164 !== null) {
            $this->messages->sendNotice(DeliveryChannel::Sms, $user->mobile_e164, $notice);
        }
    }
}
