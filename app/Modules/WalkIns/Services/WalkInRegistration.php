<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Enums\CapacityMode;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Scheduling\DTOs\WalkInDayFacts;
use App\Modules\WalkIns\DTOs\RegistrationCommand;
use App\Modules\WalkIns\DTOs\RegistrationResult;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Exceptions\CapacityOverrideRequired;
use App\Modules\WalkIns\Exceptions\DailyLimitReached;
use App\Modules\WalkIns\Exceptions\EntryAlreadyLive;
use App\Modules\WalkIns\Exceptions\IdempotencyKeyConflict;
use App\Modules\WalkIns\Exceptions\LateRiskAcknowledgementRequired;
use App\Modules\WalkIns\Exceptions\QueueFull;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Contracts\Clock;
use App\Shared\Services\Fingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in registration (increment 5.5a, plan §9, decisions 51 to 103). One request makes one entry, or none.
 *
 * Lock order (decision 96, also in doctor-presence.md): account advisory lock, patient advisory lock (remote path only), the doctor's
 * open presence session FOR SHARE (through PresenceAdmission), the location/day advisory lock (queue creation only),
 * walkin_queues FOR UPDATE, then entries, then consultations and appointments (read only). Constraints are the backstop:
 * uq_queue_number, uq_one_live_entry_per_patient, uq_entry_registrant_idempotency. Redis is not used for any decision.
 *
 * Staff and doctor registrations are guests that start WAITING (arrived now); patient-app registrations start REGISTERED.
 * Nothing here takes a payment (D-20) or verifies an HMO (principle 12). Audit rows and events carry ids and codes only.
 */
final class WalkInRegistration
{
    /** The queue this registration locked, for the unique-violation fallback (set under the lock, reset per registration). */
    private ?string $lockedQueueId = null;

    public function __construct(
        private readonly PresenceAdmission $presence,
        private readonly WalkInDay $day,
        private readonly QueueEstimates $estimates,
        private readonly RecordsAudit $audit,
        private readonly SendsNotifications $notifications,
        private readonly LockWait $locks,
        private readonly AdmissionRules $rules,
        private readonly Clock $clock,
    ) {}

    public function register(RegistrationCommand $c): RegistrationResult
    {
        // A replay is answered first, before any rule that may have changed since (remote registration turned off, the service ended): decision 99.
        $previous = $this->existing($c);
        if ($previous !== null) {
            return $previous;
        }

        $service = $c->service;
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

        if ($c->isRemote() && ($refusal = $this->rules->accountRefusal($policy, $c->user)) !== null) {
            throw $refusal;
        }

        $this->lockedQueueId = null;
        try {
            return $this->locks->run(fn () => $this->admit($c, $policy, $localDate, $now));
        } catch (UniqueConstraintViolationException $e) {
            // A competing request got there first. Both backstops answer with the entry that won.
            $message = $e->getMessage();
            if (str_contains($message, 'uq_entry_registrant_idempotency') && ($won = $this->existing($c)) !== null) {
                return $won;
            }
            if (str_contains($message, 'uq_one_live_entry_per_patient') && $c->profile !== null && ($lockedQueue = $this->lockedQueue()) !== null) {
                $live = WalkInQueueEntry::query()->where('walkin_queue_id', $lockedQueue)->where('patient_profile_id', $c->profile->id)->whereIn('status', array_map(fn (EntryStatus $s) => $s->value, EntryStatus::live()))->value('id');
                if (is_string($live)) {
                    throw new EntryAlreadyLive($live);
                }
            }

            throw $e;
        }
    }

    private function lockedQueue(): ?string
    {
        return $this->lockedQueueId;
    }

    /** The entry a request with this key already made, if its body is the same (decision 99); a different body is a 422. */
    private function existing(RegistrationCommand $c): ?RegistrationResult
    {
        $entry = WalkInQueueEntry::query()->where('registered_by_user_id', $c->user->id)->where('idempotency_key', $c->idempotencyKey)->first();
        if ($entry === null) {
            return null;
        }
        if (! $this->sameRequest($entry, $c)) {
            throw new IdempotencyKeyConflict;
        }

        return new RegistrationResult($entry, $entry->queue, null, replayed: true);
    }

    /**
     * Whether a retry carries the same body as the entry that the key made. Everything the request decides is compared: service, payer,
     * the person (the phone hash, or the name when there is no phone), priority and its reason and note, the HMO name as typed, and the
     * capacity override. A guest whose data was purged since can no longer be compared on identity, so the rest decides.
     */
    private function sameRequest(WalkInQueueEntry $entry, RegistrationCommand $c): bool
    {
        if ($entry->doctor_service_id !== $c->service->id || $entry->payer_type !== $c->payerType || $entry->triage_priority !== $c->priority || $entry->triage_reason_code !== $c->priorityReason) {
            return false;
        }
        $purged = $entry->guest_pii_purged_at !== null;
        if (! $purged) {
            if (($entry->triage_note_encrypted ?? null) !== $c->priorityNote || ($entry->hmo_card_name_encrypted ?? null) !== $c->hmoCardName) {
                return false;
            }
        }
        $metadata = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry->id)->where('event_type', 'REGISTERED')->value('metadata');
        $requestedOverride = is_string($metadata) && (json_decode($metadata, true)['capacity_override'] ?? false) === true;
        if ($requestedOverride !== $c->capacityOverride) {
            return false;
        }

        if ($c->profile !== null) {
            return $entry->patient_profile_id === $c->profile->id;
        }
        if ($entry->patient_profile_id !== null) {
            return false;
        }
        if ($purged) {
            return true;
        }
        if ($c->guestPhoneE164 !== null) {
            return $entry->guest_phone_hash !== null && hash_equals($entry->guest_phone_hash, self::phoneHash($c->guestPhoneE164));
        }

        return $entry->guest_name_encrypted !== null && mb_strtolower($entry->guest_name_encrypted) === mb_strtolower((string) $c->guestName);
    }

    public static function phoneHash(string $e164): string
    {
        return Fingerprint::of('walkin-phone:'.$e164);
    }

    private function admit(RegistrationCommand $c, WalkInPolicy $policy, string $localDate, CarbonImmutable $now): RegistrationResult
    {
        $service = $c->service;
        $locationId = (string) $service->clinic_location_id;
        $doctorId = $service->doctor_profile_id;

        if ($c->isRemote() && $c->profile !== null) {
            LockWait::advisory(LockWait::ACCOUNT, $c->user->id);
            LockWait::advisory(LockWait::PATIENT, $c->profile->id);
        }

        $presence = $this->presence->lockShared($doctorId, $locationId);

        // The schedule's facts are derived once the queue row is locked (decision 101), so every admission decision uses current data.
        [$queue, $created, $facts] = $this->lockQueue($c, $policy, $localDate, $now);
        $this->lockedQueueId = $queue->id;
        // A request with this key may have committed while this one waited for the locks: answer with its entry (decision 99).
        if (($won = $this->existing($c)) !== null) {
            return $won;
        }
        if (($refusal = $this->rules->dayRefusal($c->isRemote(), $facts, $policy, $presence, $queue->status, $now)) !== null) {
            throw $refusal;
        }

        $capacity = $policy->capacityMode === CapacityMode::Unlimited ? null : $facts->capacity;
        $this->refreshDerived($queue, $capacity, $this->rules->cutoffAt($facts, $policy));

        if ($c->profile !== null) {
            $this->assertPatientMayJoin($c, $queue, $localDate);
        }

        $held = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->whereNotIn('status', [EntryStatus::Cancelled->value, EntryStatus::NoShow->value])->count();
        $overridden = false;
        if ($capacity !== null && $held >= $capacity) {
            if ($c->isRemote()) {
                throw new QueueFull;
            }
            if (! $c->capacityOverride) {
                throw new CapacityOverrideRequired($held, $capacity);
            }
            $overridden = true;
        }

        $status = $c->isRemote() ? EntryStatus::Registered : EntryStatus::Waiting;
        $ahead = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->whereIn('status', [EntryStatus::Registered->value, EntryStatus::Waiting->value, EntryStatus::Called->value])->get();
        $inputs = $this->estimates->inputs($doctorId, $localDate, $facts, $presence, $ahead);
        $estimate = $this->estimates->forNewEntry($inputs, $this->estimates->duration($doctorId, $service->id), $status, $c->priority);
        // E1, enforced here and not only on the client: the patient is told the clinic may not see them and must confirm.
        if ($c->isRemote() && $estimate->mayNotBeServedToday === true && ! $c->acknowledgeLateRisk) {
            throw new LateRiskAcknowledgementRequired;
        }

        $number = $queue->last_number + 1;
        $queue->forceFill(['last_number' => $number])->save();

        $entry = new WalkInQueueEntry;
        $entry->forceFill([
            'walkin_queue_id' => $queue->id,
            'queue_number' => $number,
            'patient_profile_id' => $c->profile?->id,
            'guest_name_encrypted' => $c->guestName,
            'guest_phone_encrypted' => $c->guestPhoneE164,
            'guest_phone_hash' => $c->guestPhoneE164 === null ? null : self::phoneHash($c->guestPhoneE164),
            'registered_by_user_id' => $c->user->id,
            'registration_channel' => $c->channel,
            'status' => $status,
            'triage_priority' => $c->priority,
            'triage_reason_code' => $c->priorityReason,
            'triage_note_encrypted' => $c->priorityNote,
            'doctor_service_id' => $service->id,
            'payer_type' => $c->payerType,
            'hmo_card_name_encrypted' => $c->hmoCardName,
            'privacy_notice_version' => $c->guestName === null ? null : $c->privacyNoticeVersion,
            'privacy_notice_shown_by_user_id' => $c->guestName === null ? null : $c->user->id,
            'privacy_notice_shown_at' => $c->guestName === null ? null : $now,
            // A snapshot for the WALKIN_WAIT_TIME metric (estimated against actual), written once and never served to clients (decision 98).
            'estimated_call_at_min' => $estimate->callAtMin,
            'estimated_call_at_max' => $estimate->callAtMax,
            'arrived_at' => $status === EntryStatus::Waiting ? $now : null,
            'idempotency_key' => $c->idempotencyKey,
        ])->save();

        $this->record($c, $queue, $entry, $status, $created, $overridden, $held, $capacity);
        if ($c->isRemote()) {
            // Generic text only (§18.3, decision 260): no doctor, clinic, specialty or number on a lock screen.
            $this->notifications->notify($c->user->id, 'walkins.registered', 'You joined a walk-in queue', 'This is a queue number, not an appointment. Open the app to see your place and estimated wait.', ['entry_id' => $entry->id]);
        }
        Log::info('Walk-in registered', [
            'metric' => 'WALKIN_WAIT_TIME', 'queue_id' => $queue->id, 'entry_id' => $entry->id, 'channel' => $c->channel->value,
            'estimate_min_minutes' => $estimate->minMinutes, 'estimate_max_minutes' => $estimate->maxMinutes,
        ]);

        return new RegistrationResult($entry, $queue->refresh(), $estimate);
    }

    /**
     * Lazy creation (decisions 53, 54, 92): the queue row exists only after a registration is admitted. Lookup first; on a miss take
     * the location/day advisory lock, look again, then pick the prefix and insert. Returns the queue, locked FOR UPDATE, whether it is new,
     * and the day's facts derived after the lock.
     *
     * @return array{0: WalkInQueue, 1: bool, 2: WalkInDayFacts}
     */
    private function lockQueue(RegistrationCommand $c, WalkInPolicy $policy, string $localDate, CarbonImmutable $now): array
    {
        $service = $c->service;
        $locationId = (string) $service->clinic_location_id;
        $facts = null;
        $factsFor = function () use (&$facts, $service, $locationId, $localDate): WalkInDayFacts {
            return $facts ??= $this->day->forDoctorAtLocation($service->doctor_profile_id, $locationId, $localDate, $service->clinic_service_id, graceFallbackMinutes: (int) config('walkins.estimation.appointment_grace_minutes', 15));
        };
        $find = fn () => WalkInQueue::query()
            ->where('clinic_location_id', $locationId)->where('doctor_profile_id', $service->doctor_profile_id)->whereDate('local_date', $localDate)
            ->when($service->clinic_service_id === null, fn ($q) => $q->whereNull('clinic_service_id'), fn ($q) => $q->where('clinic_service_id', $service->clinic_service_id))
            ->lockForUpdate()->first();

        $queue = $find();
        if ($queue !== null) {
            return [$queue, false, $factsFor()];
        }

        LockWait::advisory(LockWait::LOCATION_DAY, $locationId.':'.$localDate);
        $queue = $find();
        if ($queue !== null) {
            return [$queue, false, $factsFor()];
        }

        $facts = $factsFor();
        $queue = new WalkInQueue;
        $queue->forceFill([
            'clinic_location_id' => $locationId,
            'doctor_profile_id' => $service->doctor_profile_id,
            'clinic_service_id' => $service->clinic_service_id,
            'local_date' => $localDate,
            'status' => QueueStatus::Open,
            'capacity' => $policy->capacityMode === CapacityMode::Unlimited ? null : $facts->capacity,
            'cutoff_at' => $this->rules->cutoffAt($facts, $policy),
            'last_number' => 0,
            'number_prefix' => $this->choosePrefix($locationId, $localDate, $policy->numberPrefix),
            'opened_at' => $now,
        ])->save();

        return [$queue, true, $facts];
    }

    /** Decision 101: capacity and cutoff are derived again at every registration, under the queue lock, and written back when they change. */
    private function refreshDerived(WalkInQueue $queue, ?int $capacity, ?CarbonImmutable $cutoffAt): void
    {
        $sameCutoff = ($queue->cutoff_at === null && $cutoffAt === null) || ($queue->cutoff_at !== null && $cutoffAt !== null && $queue->cutoff_at->equalTo($cutoffAt));
        if ($queue->capacity === $capacity && $sameCutoff) {
            return;
        }
        $queue->forceFill(['capacity' => $capacity, 'cutoff_at' => $cutoffAt])->save();
    }

    /** Distinct per location and day: the policy's prefix when it is free, otherwise the next free letter (A to Z, then two letters). Called under the location/day advisory lock. */
    private function choosePrefix(string $locationId, string $localDate, ?string $wanted): string
    {
        $used = DB::table('walkin_queues')->where('clinic_location_id', $locationId)->whereDate('local_date', $localDate)->whereNotNull('number_prefix')->pluck('number_prefix')->map(fn ($p) => (string) $p)->all();
        if ($wanted !== null && ! in_array($wanted, $used, true)) {
            return $wanted;
        }
        $letters = range('A', 'Z');
        foreach ($letters as $letter) {
            if (! in_array($letter, $used, true)) {
                return $letter;
            }
        }
        foreach ($letters as $first) {
            foreach ($letters as $second) {
                if (! in_array($first.$second, $used, true)) {
                    return $first.$second;
                }
            }
        }

        throw new \LogicException('No queue prefix is free at this location today.');
    }

    /** A duplicate in this queue, and the remote daily limits (A7, decisions 94 and 95). SKIPPED counts as live because a skipped patient can return. */
    private function assertPatientMayJoin(RegistrationCommand $c, WalkInQueue $queue, string $localDate): void
    {
        $profileId = (string) $c->profile?->id;
        $held = array_map(fn (EntryStatus $s) => $s->value, [...EntryStatus::live(), EntryStatus::Skipped]);

        $duplicate = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->where('patient_profile_id', $profileId)->whereIn('status', $held)->value('id');
        if (is_string($duplicate)) {
            throw new EntryAlreadyLive($duplicate);
        }
        if (! $c->isRemote()) {
            return;
        }

        $maxLive = (int) config('walkins.remote.max_live_entries_per_patient');
        $live = DB::table('walkin_queue_entries as e')->join('walkin_queues as q', 'q.id', '=', 'e.walkin_queue_id')
            ->where('e.patient_profile_id', $profileId)->where('q.local_date', $localDate)->whereIn('e.status', $held)->count();
        if ($live >= $maxLive) {
            throw new DailyLimitReached('live_entries_per_patient', $maxLive);
        }

        $maxPatients = (int) config('walkins.remote.max_patients_per_account_per_day');
        $patients = DB::table('walkin_queue_entries as e')->join('walkin_queues as q', 'q.id', '=', 'e.walkin_queue_id')
            ->where('e.registered_by_user_id', $c->user->id)->where('e.registration_channel', 'PATIENT_APP')->where('q.local_date', $localDate)
            ->where('e.status', '<>', EntryStatus::NoShow->value)->whereNotNull('e.patient_profile_id')->distinct()->pluck('e.patient_profile_id')->map(fn ($id) => (string) $id)->all();
        if (! in_array($profileId, $patients, true) && count($patients) >= $maxPatients) {
            throw new DailyLimitReached('patients_per_account_per_day', $maxPatients);
        }
    }

    /** Events and the audit row: ids and codes only, never a name, phone, HMO text or note (decisions 89 and 103). */
    private function record(RegistrationCommand $c, WalkInQueue $queue, WalkInQueueEntry $entry, EntryStatus $status, bool $created, bool $overridden, int $held, ?int $capacity): void
    {
        $event = fn (string $type, ?string $entryId, ?string $to, ?array $metadata) => DB::table('walkin_queue_events')->insert([
            'walkin_queue_id' => $queue->id, 'walkin_queue_entry_id' => $entryId, 'event_type' => $type, 'from_status' => null, 'to_status' => $to,
            'actor_id' => $c->user->id, 'reason' => null, 'metadata' => $metadata === null ? null : json_encode($metadata),
        ]);

        if ($created) {
            $event('QUEUE_OPENED', null, null, ['trigger' => 'first_registration']);
        }
        $event('REGISTERED', $entry->id, $status->value, array_filter([
            'registration_channel' => $c->channel->value, 'payer_type' => $c->payerType->value,
            'priority_level' => $c->priority->needsReason() ? $c->priority->apiName() : null, 'priority_reason_code' => $c->priorityReason?->value,
            'capacity_override' => $c->capacityOverride ? true : null,
        ], fn ($v) => $v !== null));
        $clinicId = DB::table('clinic_locations')->where('id', $queue->clinic_location_id)->value('clinic_id');
        $clinicId = is_string($clinicId) ? $clinicId : null;

        if ($overridden) {
            $event('CAPACITY_OVERRIDDEN', $entry->id, null, ['current' => $held, 'capacity' => $capacity]);
            $this->audit->record('walkins.capacity_overridden', 'walkin_queue_entry', $entry->id, ['queue_id' => $queue->id, 'current' => $held, 'capacity' => $capacity, 'channel' => $c->channel->value], clinicId: $clinicId);
        }
        $this->audit->record('walkins.registered', 'walkin_queue_entry', $entry->id, array_filter([
            'queue_id' => $queue->id, 'channel' => $c->channel->value, 'payer_type' => $c->payerType->value, 'is_guest' => $c->profile === null,
            'priority_level' => $c->priority->needsReason() ? $c->priority->apiName() : null, 'priority_reason_code' => $c->priorityReason?->value,
            'privacy_notice_version' => $c->profile === null ? $c->privacyNoticeVersion : null,
        ], fn ($v) => $v !== null), clinicId: $clinicId);
    }
}
