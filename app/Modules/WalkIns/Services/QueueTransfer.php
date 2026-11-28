<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Enums\CapacityMode;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Exceptions\CapacityOverrideRequired;
use App\Modules\WalkIns\Exceptions\DoctorNotAvailable;
use App\Modules\WalkIns\Exceptions\EntryAlreadyLive;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Exceptions\QueueClosedForRegistration;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves one live entry to another queue of the same location and day (increment 5.8-2, D-G). Staff only. The entry keeps its id (the patient's
 * channel and any open consultation survive), its status, triage and arrival order; it takes the next number of the target queue.
 *
 * Lock order (brief §3): the target doctor's session FOR SHARE (PresenceAdmission), then both queues FOR UPDATE in ascending id, then the entry
 * FOR UPDATE. Admission rules are the staff path's (AdmissionRules), so a transfer can never admit where registration would refuse.
 * Events and audit rows carry ids and numbers only; the patient's notice is generic and goes to the accounts that may see the entry now.
 */
final class QueueTransfer
{
    public function __construct(
        private readonly WalkInAccess $access,
        private readonly PresenceAdmission $presence,
        private readonly WalkInDay $day,
        private readonly AdmissionRules $rules,
        private readonly PatientAccess $patients,
        private readonly SendsNotifications $notifications,
        private readonly LockWait $locks,
        private readonly RecordsAudit $audit,
        private readonly QueueRealtime $realtime,
        private readonly Clock $clock,
    ) {}

    public function transfer(User $user, WalkInQueueEntry $entry, string $targetQueueId, bool $overCapacityAck): WalkInQueueEntry
    {
        $source = $entry->queue;
        $channel = $this->access->requireActing($user, 'walkins.manage_queue', $source);
        if ($channel !== RegistrationChannel::StaffConsole) {
            $this->access->refuse($user, $source);
        }

        $target = WalkInQueue::query()->find($targetQueueId) ?? abort(404, 'Not found.');
        $this->access->requireActing($user, 'walkins.manage_queue', $target);
        if ($target->id === $source->id) {
            throw ValidationException::withMessages(['walkin_queue_id' => ['The entry is already in this queue.']]);
        }
        if ($target->clinic_location_id !== $source->clinic_location_id || $target->local_date->toDateString() !== $source->local_date->toDateString()) {
            throw ValidationException::withMessages(['walkin_queue_id' => ['A transfer stays at the same clinic location and day.']]);
        }
        // Brief §5 (not_today): only today's queues move entries, as registration and call do.
        $timezone = (string) ClinicLocation::query()->whereKey($target->clinic_location_id)->value('timezone');
        if ($target->local_date->toDateString() !== $this->clock->now()->setTimezone($timezone !== '' ? $timezone : (string) config('mediconnect.timezone'))->toDateString()) {
            throw new QueueClosedForRegistration('not_today');
        }

        return $this->locks->run(function () use ($user, $entry, $source, $target, $overCapacityAck): WalkInQueueEntry {
            $targetDoctor = $target->doctor_profile_id ?? throw new DoctorNotAvailable('no_doctor');
            $presence = $this->presence->lockShared($targetDoctor, $target->clinic_location_id);
            [$lockedSource, $lockedTarget] = $this->lockQueues($source, $target);

            $current = WalkInQueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            // A concurrent transfer that moved the entry first is a retryable conflict (5.8 review), not an illegal transition.
            LockWait::assertEntryInQueue($current, $lockedSource);
            if (! in_array($current->status, [EntryStatus::Registered, EntryStatus::Waiting], true)) {
                throw InvalidEntryTransition::entry($current->status, 'transferred');
            }

            if ($presence->status === PresenceStatus::Out) {
                throw new DoctorNotAvailable('checked_out');
            }
            $assignment = DoctorClinicAssignment::query()->where('doctor_profile_id', $targetDoctor)->where('clinic_location_id', $lockedTarget->clinic_location_id)->where('status', 'ACTIVE')->first();
            if ($assignment === null) {
                throw new DoctorNotAvailable('no_assignment');
            }
            $policy = WalkInPolicy::fromStored($assignment->walkin_policy);
            $localDate = $lockedTarget->local_date->toDateString();
            $now = $this->clock->now();
            $facts = $this->day->forDoctorAtLocation($targetDoctor, $lockedTarget->clinic_location_id, $localDate, $lockedTarget->clinic_service_id, graceFallbackMinutes: (int) config('walkins.estimation.appointment_grace_minutes', 15));
            if (($refusal = $this->rules->dayRefusal(false, $facts, $policy, $presence, $lockedTarget->status, $now)) !== null) {
                throw $refusal;
            }

            if ($current->patient_profile_id !== null) {
                $existing = WalkInQueueEntry::query()->where('walkin_queue_id', $lockedTarget->id)->where('patient_profile_id', $current->patient_profile_id)
                    ->whereIn('status', array_map(fn (EntryStatus $s) => $s->value, EntryStatus::held()))->value('id');
                if (is_string($existing)) {
                    throw new EntryAlreadyLive($existing);
                }
            }

            // B9: the target's capacity is enforced like registration; an override needs the staff acknowledgement and is audited.
            $held = WalkInQueueEntry::query()->where('walkin_queue_id', $lockedTarget->id)->whereNotIn('status', [EntryStatus::Cancelled->value, EntryStatus::NoShow->value])->count();
            // The same formula registration uses under the lock (WalkInRegistration), not the stored number: the two cannot disagree.
            $capacity = $policy->capacityMode === CapacityMode::Unlimited ? null : $facts->capacity;
            $overridden = false;
            if ($capacity !== null && $held >= $capacity) {
                if (! $overCapacityAck) {
                    throw new CapacityOverrideRequired($held, $capacity);
                }
                $overridden = true;
            }

            // The entry's service must belong to the target queue's doctor, location and clinic service (walkin_queue_entries_match_service), so the
            // move takes the target doctor's in-person service there. A target with no such service cannot take the patient.
            $service = $this->targetService($lockedTarget, $current->doctor_service_id);
            if ($service === null) {
                throw ValidationException::withMessages(['walkin_queue_id' => ['The target doctor has no in-person service for this queue.']]);
            }

            $status = $current->status;
            $fromNumber = $current->queue_number;
            $number = $lockedTarget->last_number + 1;
            $lockedTarget->forceFill(['last_number' => $number])->save();
            // A place with no consultation holds no doctor: the target doctor is named only when the patient is called (the coherence check).
            $current->forceFill([
                'walkin_queue_id' => $lockedTarget->id, 'queue_number' => $number, 'doctor_service_id' => $service->id, 'assigned_doctor_profile_id' => null,
            ])->save();

            DB::table('walkin_queue_events')->insert([
                'walkin_queue_id' => $lockedTarget->id, 'walkin_queue_entry_id' => $current->id, 'event_type' => 'TRANSFERRED',
                'from_status' => $status->value, 'to_status' => $status->value, 'actor_id' => $user->id, 'reason' => null,
                'metadata' => json_encode(['from_queue_id' => $lockedSource->id, 'from_number' => $fromNumber, 'to_number' => $number, 'capacity_override' => $overridden]),
            ]);
            $this->audit->record('walkins.transferred', 'walkin_queue_entry', $current->id, [
                'from_queue_id' => $lockedSource->id, 'to_queue_id' => $lockedTarget->id, 'capacity_override' => $overridden,
            ], clinicId: $this->clinicIdOf($lockedTarget));
            if ($overridden) {
                $this->audit->record('walkins.capacity_overridden', 'walkin_queue_entry', $current->id, ['queue_id' => $lockedTarget->id, 'current' => $held, 'capacity' => $capacity, 'channel' => 'STAFF_CONSOLE'], clinicId: $this->clinicIdOf($lockedTarget));
            }

            if ($current->patient_profile_id !== null) {
                foreach ($this->patients->userIds($current->patient_profile_id) as $userId) {
                    $this->notifications->notify($userId, 'walkins.transferred', 'Your queue entry was moved', 'Open the app for your new number.', ['entry_id' => $current->id]);
                }
            }

            $this->realtime->queueChanged($lockedSource);
            $this->realtime->queueChanged($lockedTarget);
            $this->realtime->entryChanged($lockedTarget, $current);

            return $current;
        });
    }

    /**
     * The in-person, active service of the target queue's doctor at its location and clinic service. The entry's own service is kept when it still
     * matches; otherwise the lowest id is taken, so the choice is stable.
     */
    private function targetService(WalkInQueue $target, string $currentServiceId): ?DoctorService
    {
        $matching = DoctorService::query()
            ->where('doctor_profile_id', $target->doctor_profile_id)->where('clinic_location_id', $target->clinic_location_id)
            ->when($target->clinic_service_id === null, fn ($q) => $q->whereNull('clinic_service_id'), fn ($q) => $q->where('clinic_service_id', $target->clinic_service_id))
            ->where('consultation_type', 'IN_PERSON')->where('status', 'ACTIVE')->orderBy('id');

        return (clone $matching)->whereKey($currentServiceId)->first() ?? $matching->first();
    }

    /**
     * Both queues, FOR UPDATE, in ascending id order, so two opposite transfers never wait on each other in a cycle.
     *
     * @return array{WalkInQueue, WalkInQueue}
     */
    private function lockQueues(WalkInQueue $a, WalkInQueue $b): array
    {
        $rows = WalkInQueue::query()->whereIn('id', [$a->id, $b->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $first = $rows->get($a->id);
        $second = $rows->get($b->id);
        if ($first === null || $second === null) {
            throw new \LogicException('The two transfer queues must both exist and be locked.');
        }

        return [$first, $second];
    }

    private function clinicIdOf(WalkInQueue $queue): ?string
    {
        $id = ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('clinic_id');

        return is_string($id) ? $id : null;
    }
}
