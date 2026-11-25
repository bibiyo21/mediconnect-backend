<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Presence\DTOs\AdmissionPresence;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Scheduling\DTOs\WalkInDayFacts;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Exceptions\QueueClosedForRegistration;
use App\Modules\WalkIns\Exceptions\QueueNotStarted;
use App\Modules\WalkIns\Exceptions\RegistrationRefused;
use App\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/**
 * The refusals of walk-in admission, in one place and in one order, so registration and the join preview can never disagree
 * (decisions 137, 168, 179, 180). Each method returns the exception registration would throw, or null; the preview only reads its
 * reason. Order: account (remote only), then doctor and location active, clinic closed, no walk-in hours, the doctor's presence, the
 * queue's status, the cutoff.
 */
final class AdmissionRules
{
    /** A1 and decision 246: remote registration is off until the clinic turns it on, and needs the calling account's verified mobile number. */
    public function accountRefusal(WalkInPolicy $policy, User $user): ?DomainException
    {
        if (! $policy->allowPatientAppRegistration) {
            return new RegistrationRefused('remote_registration_off', 'This doctor does not take walk-in registrations from the app. Please register at the clinic.');
        }
        if ($user->mobile_e164 === null || $user->mobile_verified_at === null) {
            return new RegistrationRefused('mobile_not_verified', 'Verify your mobile number to join a walk-in queue from the app.');
        }

        return null;
    }

    public function dayRefusal(bool $remote, WalkInDayFacts $facts, WalkInPolicy $policy, AdmissionPresence $presence, ?QueueStatus $queueStatus, CarbonImmutable $now): ?DomainException
    {
        if (! $facts->doctorActive) {
            return new RegistrationRefused('doctor_not_active', 'This doctor is not taking walk-ins.');
        }
        if (! $facts->locationActive) {
            return new RegistrationRefused('location_not_active', 'This clinic location is not taking walk-ins.');
        }
        if ($facts->clinicClosedToday) {
            return new QueueClosedForRegistration('clinic_closed_today');
        }
        if (! $facts->hasWalkInHours) {
            return new RegistrationRefused('no_walkin_hours_today', 'This doctor has no walk-in hours today.');
        }
        // Decision 179: remote needs the doctor in (IN, BUSY, ON_BREAK); staff may register before check-in (B2) but not after check-out.
        if ($presence->status === PresenceStatus::Out) {
            return new RegistrationRefused('doctor_checked_out', 'This doctor has checked out for today.');
        }
        if ($remote && $presence->status === PresenceStatus::NotCheckedIn) {
            return new QueueNotStarted;
        }
        if ($remote && $presence->status === PresenceStatus::TemporarilyUnavailable) {
            return new RegistrationRefused('doctor_unavailable', 'This doctor is not available right now, so the app cannot add you. Please register at the clinic.');
        }
        if ($queueStatus === QueueStatus::Paused) {
            return new QueueClosedForRegistration('paused');
        }
        if ($queueStatus === QueueStatus::Closed) {
            return new QueueClosedForRegistration('closed');
        }
        $cutoff = $this->cutoffAt($facts, $policy);
        if ($cutoff !== null && $now >= $cutoff) {
            return new QueueClosedForRegistration('cutoff_passed');
        }

        return null;
    }

    /** The earlier of the clinic's closing and the last walk-in window's end, minus the policy offset (Q8, B8: split shifts use the last window). */
    public function cutoffAt(WalkInDayFacts $facts, WalkInPolicy $policy): ?CarbonImmutable
    {
        $base = array_values(array_filter([$facts->clinicClosesAt, $facts->lastWindowEndsAt]));
        if ($base === []) {
            return null;
        }
        $earliest = array_reduce($base, fn (?CarbonImmutable $c, CarbonImmutable $x) => $c === null || $x < $c ? $x : $c);

        return $earliest->subMinutes($policy->cutoffMinutesBeforeClose);
    }
}
