<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Consultations\Contracts\ConsultationIntake;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entry and queue moves of increment 5.5a: mark arrived, cancel, pause and open a queue (decisions 51 and 52). Calling, skipping,
 * no-show and closing arrive in 5.6 and 5.8. Each move locks the queue row and then the entry (the Phase 5 lock order), checks the
 * move against the current state, writes an event and an audit row (ids and codes only), and says 409 INVALID_STATE_TRANSITION otherwise.
 */
final class EntryActions
{
    public function __construct(
        private readonly WalkInAccess $access,
        private readonly WalkInReader $reader,
        private readonly PatientAccess $patients,
        private readonly RecordsAudit $audit,
        private readonly SendsNotifications $notifications,
        private readonly LockWait $locks,
        private readonly Clock $clock,
        private readonly QueueRealtime $realtime,
        private readonly ConsultationIntake $intake,
    ) {}

    /** REGISTERED to WAITING: the patient is at the clinic. Sets arrived_at, the call-order key (B5). */
    public function arrive(User $user, WalkInQueueEntry $entry): WalkInQueueEntry
    {
        $queue = $entry->queue;
        $this->requireActing($user, $queue);

        return $this->locks->run(function () use ($user, $entry, $queue): WalkInQueueEntry {
            $this->lockQueueThenEntry($queue, $entry);
            $current = WalkInQueueEntry::query()->whereKey($entry->id)->firstOrFail();
            if ($current->status !== EntryStatus::Registered) {
                throw InvalidEntryTransition::entry($current->status, 'marked as arrived');
            }
            $now = $this->clock->now();
            $current->forceFill(['status' => EntryStatus::Waiting, 'arrived_at' => $now, 'queue_sort_at' => $now])->save();
            $this->event($queue, $current, 'ARRIVED', EntryStatus::Registered, EntryStatus::Waiting, $user, null, null);
            $this->audit->record('walkins.arrived', 'walkin_queue_entry', $current->id, ['queue_id' => $queue->id], clinicId: $this->clinicId($queue));
            $this->realtime->entryChanged($queue, $current);

            return $current;
        });
    }

    /**
     * Withdraws an entry that is REGISTERED, WAITING or SKIPPED (D15): by its owner, or by staff or the doctor (who need patient_informed_in_person for a
     * guest, who has no notification channel). Frees the place. Only the reason code is stored; a patient's entry cancelled by staff gets a generic in-app notice.
     */
    public function cancel(User $user, WalkInQueueEntry $entry, CancelReason $reason, bool $patientInformedInPerson): WalkInQueueEntry
    {
        $queue = $entry->queue;
        $channel = $this->access->actingChannel($user, 'walkins.manage_queue', $queue->clinic_location_id, $queue->doctor_profile_id);
        // The owner is judged now (self, or a guardian still authorized) and needs the patient permission to register and cancel (decision 52).
        $owner = $user->can('walkins.register') && $this->reader->owns($user, $entry);
        if ($channel === null && ! $owner) {
            $this->refuse($user, $queue);
        }
        $byStaff = $channel !== null;
        if ($byStaff && $entry->isGuest() && ! $patientInformedInPerson) {
            throw ValidationException::withMessages(['patient_informed_in_person' => ['Tell the patient in person, then confirm it here: a guest has no notification.']]);
        }

        return $this->locks->run(function () use ($user, $entry, $queue, $reason, $byStaff, $patientInformedInPerson): WalkInQueueEntry {
            $this->lockQueueThenEntry($queue, $entry);
            $current = WalkInQueueEntry::query()->whereKey($entry->id)->firstOrFail();
            if (! EntryTransitions::allows($current->status, EntryStatus::Cancelled)) {
                throw InvalidEntryTransition::entry($current->status, 'cancelled');
            }
            $from = $current->status;
            $current->forceFill(['status' => EntryStatus::Cancelled, 'assigned_doctor_profile_id' => null])->save();
            // A skipped patient's consultation (SCHEDULED, then cancelled by the skip) stays cancelled; any care relationship for the entry ends here (idempotent).
            $this->intake->cancelForWalkIn($current->id, $reason->value);
            $this->event($queue, $current, 'CANCELLED', $from, EntryStatus::Cancelled, $user, $reason->value, array_filter(['by' => $byStaff ? 'STAFF' : 'PATIENT', 'patient_informed_in_person' => $byStaff && $current->isGuest() ? $patientInformedInPerson : null], fn ($v) => $v !== null));
            $this->audit->record('walkins.cancelled', 'walkin_queue_entry', $current->id, ['queue_id' => $queue->id, 'reason_code' => $reason->value, 'by' => $byStaff ? 'STAFF' : 'PATIENT'], clinicId: $this->clinicId($queue));
            if ($byStaff && $current->patient_profile_id !== null) {
                // To the accounts that may see this entry now (the profile's own and its authorized guardians), never to a guardian since revoked.
                foreach ($this->patients->userIds($current->patient_profile_id) as $userId) {
                    $this->notifications->notify($userId, 'walkins.cancelled', 'Your walk-in queue entry was cancelled', 'Open the app for details.', ['entry_id' => $current->id]);
                }
            }
            $this->realtime->entryChanged($queue, $current);

            return $current;
        });
    }

    /** OPEN to PAUSED: no new registrations (QUEUE_CLOSED, reason `paused`); entries already in the queue are untouched. */
    public function pause(User $user, WalkInQueue $queue): WalkInQueue
    {
        return $this->switch($user, $queue, QueueStatus::Open, QueueStatus::Paused, 'QUEUE_PAUSED', 'walkins.queue_paused', 'paused');
    }

    /** PAUSED to OPEN. A closed queue stays closed (closing and its dispositions arrive in 5.8). */
    public function open(User $user, WalkInQueue $queue): WalkInQueue
    {
        return $this->switch($user, $queue, QueueStatus::Paused, QueueStatus::Open, 'QUEUE_OPENED', 'walkins.queue_opened', 'opened');
    }

    private function switch(User $user, WalkInQueue $queue, QueueStatus $from, QueueStatus $to, string $eventType, string $auditAction, string $word): WalkInQueue
    {
        $this->requireActing($user, $queue);

        return $this->locks->run(function () use ($user, $queue, $from, $to, $eventType, $auditAction, $word): WalkInQueue {
            $locked = WalkInQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw InvalidEntryTransition::queue($locked->status, $word);
            }
            $locked->forceFill(['status' => $to])->save();
            DB::table('walkin_queue_events')->insert([
                'walkin_queue_id' => $locked->id, 'walkin_queue_entry_id' => null, 'event_type' => $eventType, 'from_status' => null, 'to_status' => null,
                'actor_id' => $user->id, 'reason' => null, 'metadata' => json_encode(['from' => $from->value, 'to' => $to->value]),
            ]);
            $this->audit->record($auditAction, 'walkin_queue', $locked->id, ['from' => $from->value, 'to' => $to->value], clinicId: $this->clinicId($locked));
            $this->realtime->queueChanged($locked);

            return $locked;
        });
    }

    private function requireActing(User $user, WalkInQueue $queue): void
    {
        $this->access->requireActing($user, 'walkins.manage_queue', $queue);
    }

    private function refuse(User $user, WalkInQueue $queue): never
    {
        $this->access->refuse($user, $queue);
    }

    /** The Phase 5 lock order: the queue row, then the entry. */
    private function lockQueueThenEntry(WalkInQueue $queue, WalkInQueueEntry $entry): void
    {
        WalkInQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
        WalkInQueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
    }

    /** @param  array<string, mixed>|null  $metadata */
    private function event(WalkInQueue $queue, WalkInQueueEntry $entry, string $type, EntryStatus $from, EntryStatus $to, User $actor, ?string $reason, ?array $metadata): void
    {
        DB::table('walkin_queue_events')->insert([
            'walkin_queue_id' => $queue->id, 'walkin_queue_entry_id' => $entry->id, 'event_type' => $type, 'from_status' => $from->value, 'to_status' => $to->value,
            'actor_id' => $actor->id, 'reason' => $reason, 'metadata' => $metadata === null || $metadata === [] ? null : json_encode($metadata),
        ]);
    }

    private function clinicId(WalkInQueue $queue): ?string
    {
        $id = ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('clinic_id');

        return is_string($id) ? $id : null;
    }
}
