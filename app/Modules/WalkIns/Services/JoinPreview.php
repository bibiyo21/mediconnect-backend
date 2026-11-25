<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\DTOs\AdmissionPresence;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Exceptions\QueueClosedForRegistration;
use App\Modules\WalkIns\Exceptions\QueueNotStarted;
use App\Modules\WalkIns\Exceptions\RegistrationRefused;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Contracts\Clock;
use App\Shared\Exceptions\DomainException;
use Illuminate\Validation\ValidationException;

/**
 * What a patient sees before joining (E8, decision 291): the queue's state at the location, whether the app may register them, the listed
 * fee, and a patient-safe estimate for a new remote entry. No counts and no remaining places (E2). Advisory: registration decides under lock.
 */
final class JoinPreview
{
    public function __construct(
        private readonly WalkInDay $day,
        private readonly PresenceAdmission $presence,
        private readonly QueueEstimates $estimates,
        private readonly AdmissionRules $rules,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user, DoctorService $service): array
    {
        $locationId = (string) $service->clinic_location_id;
        $doctorId = $service->doctor_profile_id;
        $location = ClinicLocation::query()->findOrFail($locationId);
        $now = $this->clock->now();
        $localDate = $now->setTimezone($location->timezone)->toDateString();

        $assignment = DoctorClinicAssignment::query()->where('doctor_profile_id', $doctorId)->where('clinic_location_id', $locationId)->where('status', 'ACTIVE')->first();
        if ($service->status !== 'ACTIVE' || $assignment === null || ! $this->day->offersWalkIn($service->id, $localDate)) {
            throw ValidationException::withMessages(['doctor_service_id' => ['This service is not available for walk-ins at this location.']]);
        }
        $policy = WalkInPolicy::fromStored($assignment->walkin_policy);
        $facts = $this->day->forDoctorAtLocation($doctorId, $locationId, $localDate, $service->clinic_service_id, graceFallbackMinutes: (int) config('walkins.estimation.appointment_grace_minutes', 15));
        $presence = $this->presence->current($doctorId, $locationId);
        $queue = WalkInQueue::query()->where('clinic_location_id', $locationId)->where('doctor_profile_id', $doctorId)->whereDate('local_date', $localDate)
            ->when($service->clinic_service_id === null, fn ($q) => $q->whereNull('clinic_service_id'), fn ($q) => $q->where('clinic_service_id', $service->clinic_service_id))->first();

        // The same rules, in the same order, as registration (AdmissionRules): the preview only reads their reasons.
        $day = $this->rules->dayRefusal(true, $facts, $policy, $presence, $queue?->status, $now);
        $account = $this->rules->accountRefusal($policy, $user);
        // The state names what the queue is, so a closing reason (closed, cutoff passed, clinic closed) outranks the doctor being away; the refusal
        // below keeps registration's order. Read by asking the same rules as if the doctor were in.
        $closing = $day !== null && ! $day instanceof QueueNotStarted ? $this->rules->dayRefusal(true, $facts, $policy, new AdmissionPresence(PresenceStatus::In), $queue?->status, $now) : null;
        $shown = $closing instanceof QueueClosedForRegistration ? $closing : $day;
        [$state, $stateReason] = match (true) {
            $shown === null => ['OPEN', null],
            $shown instanceof QueueNotStarted => ['NOT_STARTED', null],
            // The doctor stepped away: the queue is effectively paused for the app. Never OPEN while the doctor is out or unavailable.
            $this->reasonOf($shown) === 'paused', $this->reasonOf($shown) === 'doctor_unavailable' => ['PAUSED', $this->reasonOf($shown)],
            default => ['CLOSED', $this->reasonOf($shown)],
        };
        $refusal = $account ?? $day;

        $estimate = null;
        $lateRisk = false;
        if ($refusal === null) {
            $entries = $queue === null ? [] : WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->whereIn('status', [EntryStatus::Registered->value, EntryStatus::Waiting->value, EntryStatus::Called->value])->get();
            $inputs = $this->estimates->inputs($doctorId, $localDate, $facts, $presence, $entries);
            $new = $this->estimates->forNewEntry($inputs, $this->estimates->duration($doctorId, $service->id), EntryStatus::Registered, PriorityLevel::Normal);
            $estimate = EstimateView::forPatient($new);
            // No place in line before joining: that would be a count of the queue (E2).
            unset($estimate['position']);
            $lateRisk = $new->mayNotBeServedToday === true;
        }

        return [
            'doctor_service_id' => $service->id,
            'service_name' => $service->name,
            'doctor_name' => DoctorProfile::query()->whereKey($doctorId)->value('display_name'),
            'clinic_location_id' => $locationId,
            'location_name' => $location->name,
            'timezone' => $location->timezone,
            'local_date' => $localDate,
            'state' => $state,
            'state_reason_code' => $stateReason,
            'accepts_remote_registration' => $refusal === null,
            'remote_refusal_reason_code' => $refusal === null ? null : $this->reasonOf($refusal),
            'fee' => ['amount' => $service->fee()->amount, 'currency' => $service->fee()->currency],
            'estimate' => $estimate,
            'requires_late_risk_acknowledgement' => $lateRisk,
        ];
    }

    private function reasonOf(DomainException $e): string
    {
        return match (true) {
            $e instanceof RegistrationRefused => $e->reason,
            $e instanceof QueueClosedForRegistration => $e->reason,
            default => 'queue_not_started',
        };
    }
}
