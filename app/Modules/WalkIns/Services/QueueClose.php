<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\DTOs\AdmissionPresence;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\DTOs\CloseDisposition;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Enums\CloseAction;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Exceptions\ActiveEntriesBlockClose;
use App\Modules\WalkIns\Exceptions\DoctorCheckedInBlocksClose;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a walk-in queue for the day (increment 5.8, D-F). A closed queue admits nobody and is never reopened. Every live entry (REGISTERED,
 * WAITING, SKIPPED) needs a disposition; the system never picks one (B7). A CALLED or IN_CONSULTATION entry blocks the close.
 *
 * Lock order (brief §3): the doctor's session FOR SHARE (through PresenceAdmission, so a check-in waits or a check-out already committed), the
 * queue FOR UPDATE, the queue's entries FOR UPDATE in ascending id, then each cancellation (EntryActions: consultations and care relationships).
 * Events and audit rows carry ids and codes only; the notice is generic.
 */
final class QueueClose
{
    public function __construct(
        private readonly WalkInAccess $access,
        private readonly PresenceAdmission $presence,
        private readonly EntryActions $entries,
        private readonly LockWait $locks,
        private readonly RecordsAudit $audit,
        private readonly QueueRealtime $realtime,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<CloseDisposition>  $dispositions
     */
    public function close(User $user, WalkInQueue $queue, array $dispositions): WalkInQueue
    {
        $channel = $this->access->requireActing($user, 'walkins.manage_queue', $queue);
        $by = $channel === RegistrationChannel::DoctorApp ? 'DOCTOR' : 'STAFF';

        return $this->locks->run(function () use ($user, $queue, $dispositions, $by): WalkInQueue {
            // Only today's queue is blocked by the doctor's presence: a past-day queue (local date before today, n2) closes whatever the doctor is doing today.
            $today = $this->isToday($queue);
            $presence = $queue->doctor_profile_id === null || ! $today ? null : $this->presence->lockShared((string) $queue->doctor_profile_id, $queue->clinic_location_id);
            if ($presence !== null && $this->checkedInHere($presence)) {
                throw new DoctorCheckedInBlocksClose;
            }

            $locked = WalkInQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [QueueStatus::Open, QueueStatus::Paused], true)) {
                throw InvalidEntryTransition::queue($locked->status, 'closed');
            }

            $entries = WalkInQueueEntry::query()->where('walkin_queue_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $this->refuseActive($entries);
            $held = $entries->filter(fn (WalkInQueueEntry $e) => in_array($e->status, [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Skipped], true))
                ->keyBy(fn (WalkInQueueEntry $e) => (string) $e->id);
            $this->assertDispositions($held, $dispositions);

            // The reason is the doctor's check-out when the doctor had checked out here today, and OTHER when the doctor was never in, or the queue is a past day (brief D-F).
            $reason = $presence?->status === PresenceStatus::Out ? CancelReason::DoctorCheckedOut : CancelReason::Other;
            $byAction = [];
            foreach ($dispositions as $disposition) {
                $byAction[$disposition->entryId] = $disposition->action;
            }
            foreach ($held as $entryId => $entry) {
                $action = $byAction[$entryId];
                if ($action === CloseAction::CancelWithNotice) {
                    $this->entries->cancelUnderLock($locked, $entry, $reason, $by, $user->id, [], notify: true);
                } else {
                    // Told in person: no notice is sent, and the confirmation is recorded on the event (ids and codes only).
                    $this->entries->cancelUnderLock($locked, $entry, $reason, $by, $user->id, ['patient_informed_in_person' => true], notify: false);
                }
            }

            $from = $locked->status;
            $now = $this->clock->now();
            $locked->forceFill(['status' => QueueStatus::Closed, 'closed_at' => $now, 'closed_by' => $user->id])->save();
            DB::table('walkin_queue_events')->insert([
                'walkin_queue_id' => $locked->id, 'walkin_queue_entry_id' => null, 'event_type' => 'QUEUE_CLOSED', 'from_status' => null, 'to_status' => null,
                'actor_id' => $user->id, 'reason' => null, 'metadata' => json_encode(['from' => $from->value, 'to' => QueueStatus::Closed->value, 'entries_cancelled' => $held->count()]),
            ]);
            $this->audit->record('walkins.queue_closed', 'walkin_queue', $locked->id, ['from' => $from->value, 'to' => QueueStatus::Closed->value, 'entries_cancelled' => $held->count()], clinicId: $this->clinicIdOf($locked));
            $this->realtime->queueChanged($locked);

            return $locked;
        });
    }

    /** The queue's local date is today in its location's timezone (as QueueTransfer computes it). */
    private function isToday(WalkInQueue $queue): bool
    {
        $timezone = (string) ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('timezone');

        return $queue->local_date->toDateString() === $this->clock->now()->setTimezone($timezone !== '' ? $timezone : (string) config('mediconnect.timezone'))->toDateString();
    }

    /** Checked in at this location: IN, on a break, or temporarily unavailable. OUT and NOT_CHECKED_IN are not. */
    private function checkedInHere(AdmissionPresence $presence): bool
    {
        return in_array($presence->status, [PresenceStatus::In, PresenceStatus::OnBreak, PresenceStatus::TemporarilyUnavailable], true);
    }

    /** @param  \Illuminate\Database\Eloquent\Collection<int, WalkInQueueEntry>  $entries */
    private function refuseActive($entries): void
    {
        $inProgress = array_values($entries->filter(fn (WalkInQueueEntry $e) => $e->status === EntryStatus::InConsultation)->pluck('id')->map(fn ($id) => (string) $id)->all());
        if ($inProgress !== []) {
            throw ActiveEntriesBlockClose::inProgress($inProgress);
        }
        $called = array_values($entries->filter(fn (WalkInQueueEntry $e) => $e->status === EntryStatus::Called)->pluck('id')->map(fn ($id) => (string) $id)->all());
        if ($called !== []) {
            throw ActiveEntriesBlockClose::called($called);
        }
    }

    /**
     * The dispositions must name every held entry exactly once and no other entry. A guest cannot be cancelled with a notice (no channel).
     *
     * @param  Collection<string, WalkInQueueEntry>  $held
     * @param  list<CloseDisposition>  $dispositions
     */
    private function assertDispositions($held, array $dispositions): void
    {
        $given = array_map(fn (CloseDisposition $d) => $d->entryId, $dispositions);
        $missing = array_values(array_diff($held->keys()->all(), $given));
        $unknown = array_values(array_diff($given, $held->keys()->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages(['dispositions' => $missing]);
        }
        if ($unknown !== []) {
            throw ValidationException::withMessages(['dispositions' => $unknown]);
        }
        foreach ($dispositions as $disposition) {
            $entry = $held->get($disposition->entryId);
            if ($entry !== null && $disposition->action === CloseAction::CancelWithNotice && $entry->patient_profile_id === null) {
                throw ValidationException::withMessages(['dispositions' => ["A guest has no notification: record TOLD_IN_PERSON for {$disposition->entryId} once you have told them."]]);
            }
        }
    }

    private function clinicIdOf(WalkInQueue $queue): ?string
    {
        $id = ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('clinic_id');

        return is_string($id) ? $id : null;
    }
}
