<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Exceptions\InvalidOnboardingTransition;
use App\Modules\Doctors\Models\DoctorProfile;
use Illuminate\Support\Facades\DB;

/**
 * The only way a doctor profile changes onboarding status (CLAUDE.md §5.2).
 * Transitions are explicit (OnboardingStatus::next), serialised with a row
 * lock, and each one is written to doctor_verification_events (append-only).
 */
final class DoctorOnboarding
{
    public function __construct(private readonly RecordsAudit $audit) {}

    public function transition(DoctorProfile $profile, OnboardingStatus $to, ?string $actorId, ?string $reason = null): DoctorProfile
    {
        return DB::transaction(function () use ($profile, $to, $actorId, $reason) {
            /** @var DoctorProfile $locked */
            $locked = DoctorProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $from = $locked->onboarding_status;

            if (! $from->canMoveTo($to)) {
                throw InvalidOnboardingTransition::between($from, $to);
            }

            $locked->onboarding_status = $to;
            if ($to === OnboardingStatus::Verified) {
                $locked->license_verification_status = 'VERIFIED';
                $locked->verified_at = now()->toImmutable();
                $locked->setAttribute('verified_by', $actorId);
            }
            if ($to === OnboardingStatus::Rejected) {
                $locked->license_verification_status = 'REJECTED';
            }
            $locked->save();

            DB::table('doctor_verification_events')->insert([
                'doctor_profile_id' => $locked->id, 'from_status' => $from->value, 'to_status' => $to->value,
                'actor_id' => $actorId, 'reason' => $reason, 'occurred_at' => now(),
            ]);
            $this->audit->record('doctor.onboarding_changed', 'doctor_profile', $locked->id, ['from' => $from->value, 'to' => $to->value]);

            return $locked;
        });
    }

    /** Records the start of onboarding (no previous status). */
    public function started(DoctorProfile $profile, string $actorId): void
    {
        DB::table('doctor_verification_events')->insert([
            'doctor_profile_id' => $profile->id, 'from_status' => null, 'to_status' => OnboardingStatus::Registered->value,
            'actor_id' => $actorId, 'reason' => null, 'occurred_at' => now(),
        ]);
    }
}
