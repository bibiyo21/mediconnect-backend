<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Consultations\Contracts\ConsultationIntake;
use App\Modules\Consultations\DTOs\WalkInCallIntake;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\PriorityReason;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Exceptions\AppointmentWindowProtected;
use App\Modules\WalkIns\Exceptions\DoctorAlreadyCalling;
use App\Modules\WalkIns\Exceptions\DoctorNotAvailable;
use App\Modules\WalkIns\Exceptions\EntryAlreadyCalled;
use App\Modules\WalkIns\Exceptions\EntryAlreadyLive;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Exceptions\NotNextInOrder;
use App\Modules\WalkIns\Exceptions\QueueClosedForRegistration;
use App\Modules\WalkIns\Exceptions\QueueEmpty;
use App\Modules\WalkIns\Exceptions\RecallLimitReached;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queue operations of increment 5.6-2: call-next, call, recall, skip, no-show, return and priority (decisions D4 to D18 of the 5.6 plan).
 *
 * Lock order on the call paths (doctor-presence.md): the doctor's open presence session FOR SHARE (a check-out waits for a call in progress),
 * the doctor-call advisory lock (one call at a time per doctor, across queues), the queue row FOR UPDATE, then entries, then consultations.
 * The other moves take the queue row and then the entry. Constraints are the backstop (uq_one_called_entry_per_queue and _per_doctor); Redis
 * decides nothing. Events and audit rows carry ids and codes only: never a guest's name or phone, a note, or a clinical word.
 * Notices to the patient are generic, in-app only, and go to the accounts that may see the entry now (a guest has none).
 */
final class QueueOperations
{
    public function __construct(
        private readonly WalkInAccess $access,
        private readonly PatientAccess $patients,
        private readonly PresenceAdmission $presence,
        private readonly WalkInDay $day,
        private readonly QueueEstimates $estimates,
        private readonly ConsultationIntake $intake,
        private readonly RecordsAudit $audit,
        private readonly SendsNotifications $notifications,
        private readonly LockWait $locks,
        private readonly Clock $clock,
        private readonly QueueRealtime $realtime,
    ) {}

    /** Calls the head of the queue order. Never overrides a protected appointment window. */
    public function callNext(User $user, WalkInQueue $queue): WalkInQueueEntry
    {
        return $this->performCall($user, $queue, null, null);
    }

    /**
     * Calls one entry, which must be the head of the order. An override reason (with walkins.manage_queue, and a triage above normal) lets
     * the call pass an appointment-window refusal only.
     */
    public function call(User $user, WalkInQueueEntry $entry, ?PriorityReason $override): WalkInQueueEntry
    {
        return $this->performCall($user, $entry->queue, $entry->id, $override);
    }

    private function performCall(User $user, WalkInQueue $queue, ?string $targetId, ?PriorityReason $override): WalkInQueueEntry
    {
        $this->access->requireActing($user, 'walkins.call_patient', $queue);
        if ($override !== null) {
            $this->access->requireActing($user, 'walkins.manage_queue', $queue);
        }
        $doctorId = $queue->doctor_profile_id ?? throw new DoctorNotAvailable('no_doctor');

        try {
            return $this->locks->run(fn () => $this->callLocked($user, $queue, $doctorId, $targetId, $override));
        } catch (UniqueConstraintViolationException $e) {
            // The backstop: the advisory lock and the checks make this unreachable in normal use. The transaction is gone, so the winner is looked up now.
            if (str_contains($e->getMessage(), 'uq_one_called_entry_per_doctor')) {
                throw new DoctorAlreadyCalling($this->doctorCallingReason($doctorId, $queue));
            }
            if (str_contains($e->getMessage(), 'uq_one_called_entry_per_queue')) {
                $called = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->where('status', EntryStatus::Called->value)->value('id');

                throw new EntryAlreadyCalled(null, is_string($called) ? $called : null);
            }

            throw $e;
        }
    }

    private function callLocked(User $user, WalkInQueue $queue, string $doctorId, ?string $targetId, ?PriorityReason $override): WalkInQueueEntry
    {
        $presence = $this->presence->lockShared($doctorId, $queue->clinic_location_id);
        if ($presence->status !== PresenceStatus::In) {
            throw new DoctorNotAvailable(match ($presence->status) {
                PresenceStatus::OnBreak => 'on_break',
                PresenceStatus::Out => 'checked_out',
                PresenceStatus::NotCheckedIn => 'not_checked_in',
                default => 'unavailable',
            });
        }
        LockWait::advisory(LockWait::DOCTOR_CALL, $doctorId);
        $locked = WalkInQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
        // Read under the queue lock, so called_at is the moment the call is actually made.
        $now = $this->clock->now();
        $this->requireDay($locked);
        if (! DoctorClinicAssignment::activeAt($doctorId, $locked->clinic_location_id)) {
            throw new DoctorNotAvailable('no_assignment');
        }

        if ($targetId !== null) {
            $target = WalkInQueueEntry::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
            if (! EntryTransitions::allows($target->status, EntryStatus::Called)) {
                throw InvalidEntryTransition::entry($target->status, 'called');
            }
        }
        $calledHere = WalkInQueueEntry::query()->where('walkin_queue_id', $locked->id)->where('status', EntryStatus::Called->value)->value('id');
        if (is_string($calledHere)) {
            throw new EntryAlreadyCalled(null, $calledHere);
        }
        if ($this->calledElsewhereDate($doctorId) !== null) {
            throw new DoctorAlreadyCalling($this->doctorCallingReason($doctorId, $locked));
        }

        $head = WalkInQueueEntry::query()->where('walkin_queue_id', $locked->id)->where('status', EntryStatus::Waiting->value)
            ->orderByDesc('triage_priority')->orderBy('queue_sort_at')->orderBy('queue_number')->orderBy('id')->limit(1)->lockForUpdate()->first();
        if ($head === null) {
            throw new QueueEmpty;
        }
        if ($targetId !== null && $head->id !== $targetId) {
            throw new NotNextInOrder;
        }
        if ($override !== null && $head->triage_priority === PriorityLevel::Normal) {
            throw ValidationException::withMessages(['override_reason_code' => ['An override applies only to a patient with a priority above normal.']]);
        }

        // The schedule's facts are derived now, under the queue lock (decision 101), so the check uses current data.
        $facts = $this->day->forDoctorAtLocation($doctorId, $locked->clinic_location_id, $locked->local_date->toDateString(), $locked->clinic_service_id, graceFallbackMinutes: (int) config('walkins.estimation.appointment_grace_minutes', 15));
        if (! $facts->doctorActive) {
            throw new DoctorNotAvailable('doctor_not_active');
        }
        if (! $facts->locationActive) {
            throw new DoctorNotAvailable('location_not_active');
        }
        $conflict = $this->estimates->protectedConflict($facts, $this->estimates->callWindowStart($doctorId), $this->estimates->duration($doctorId, $head->doctor_service_id)->seconds);
        $passed = null; // the override reason, only when it actually passed a refusal
        if ($conflict !== null) {
            if ($override === null) {
                throw new AppointmentWindowProtected($conflict['reason'], null, $conflict['until']->utc()->format('Y-m-d\TH:i:s\Z'));
            }
            $passed = $override;
        }

        $head->forceFill(['status' => EntryStatus::Called, 'called_at' => $now, 'assigned_doctor_profile_id' => $doctorId, 'recall_count' => 0])->save();
        $metadata = $passed === null ? null : ['override' => true, 'reason_code' => $passed->value];
        $this->event($locked, $head, 'CALLED', EntryStatus::Waiting, EntryStatus::Called, $user, null, $metadata);
        $this->intake->openForWalkIn(new WalkInCallIntake($head->id, $doctorId, $head->patient_profile_id, $locked->clinic_location_id, $now));
        $this->audit->record('walkins.called', 'walkin_queue_entry', $head->id, array_filter(['queue_id' => $locked->id, 'override' => $passed === null ? null : true, 'override_reason_code' => $passed?->value], fn ($v) => $v !== null), clinicId: $this->clinicId($locked));
        $this->notify($head, 'walkins.called', 'Your turn at the clinic', 'Please go to the clinic desk now.');
        $this->realtime->entryChanged($locked, $head);

        return $head;
    }

    /** Calls the same called patient again (CALLED only); no automatic transition, the count only grows until the policy's limit (D9). */
    public function recall(User $user, WalkInQueueEntry $entry): WalkInQueueEntry
    {
        $queue = $entry->queue;
        $this->access->requireActing($user, 'walkins.call_patient', $queue);

        return $this->locks->run(function () use ($user, $entry, $queue): WalkInQueueEntry {
            [$locked, $current] = $this->lockBoth($queue, $entry);
            if ($current->status !== EntryStatus::Called) {
                throw InvalidEntryTransition::entry($current->status, 'recalled');
            }
            $this->requireDay($locked);
            if ($current->recall_count >= $this->recallLimit($locked)) {
                throw new RecallLimitReached;
            }
            $current->forceFill(['recall_count' => $current->recall_count + 1])->save();
            $this->event($locked, $current, 'RECALLED', EntryStatus::Called, EntryStatus::Called, $user, null, ['recall_count' => $current->recall_count]);
            $this->audit->record('walkins.recalled', 'walkin_queue_entry', $current->id, ['queue_id' => $locked->id, 'recall_count' => $current->recall_count], clinicId: $this->clinicId($locked));
            $this->notify($current, 'walkins.recalled', "Reminder: it's your turn", 'Reminder: it is your turn. Please check in at the clinic desk.');
            $this->realtime->entryChanged($locked, $current);

            return $current;
        });
    }

    /** CALLED to SKIPPED: the patient did not come. The consultation made at the call is cancelled and the doctor is free to call again. */
    public function skip(User $user, WalkInQueueEntry $entry): WalkInQueueEntry
    {
        return $this->leaveCalled($user, $entry, EntryStatus::Skipped, 'SKIPPED', 'skipped', 'walkins.skipped', 'Your turn was skipped', 'Ask at the clinic desk if you are still here. Your place is held for now.');
    }

    /** SKIPPED to NO_SHOW always; CALLED to NO_SHOW only once the recall limit is reached (D9). */
    public function noShow(User $user, WalkInQueueEntry $entry): WalkInQueueEntry
    {
        return $this->leaveCalled($user, $entry, EntryStatus::NoShow, 'NO_SHOW', 'marked as a no-show', 'walkins.no_show', 'Your queue entry was closed', 'Please ask the clinic desk.');
    }

    private function leaveCalled(User $user, WalkInQueueEntry $entry, EntryStatus $to, string $eventType, string $word, string $type, string $title, string $body): WalkInQueueEntry
    {
        $queue = $entry->queue;
        $this->access->requireActing($user, 'walkins.manage_queue', $queue);

        return $this->locks->run(function () use ($user, $entry, $queue, $to, $eventType, $word, $type, $title, $body): WalkInQueueEntry {
            [$locked, $current] = $this->lockBoth($queue, $entry);
            $from = $current->status;
            if (! EntryTransitions::allows($from, $to)
                || ($to === EntryStatus::NoShow && $from === EntryStatus::Called && $current->recall_count < $this->recallLimit($locked))) {
                throw InvalidEntryTransition::entry($from, $word);
            }
            $current->forceFill(['status' => $to, 'assigned_doctor_profile_id' => null])->save();
            $this->intake->cancelForWalkIn($current->id);
            $this->event($locked, $current, $eventType, $from, $to, $user, null, $from === EntryStatus::Called ? ['recall_count' => $current->recall_count] : null);
            $this->audit->record('walkins.'.strtolower($eventType), 'walkin_queue_entry', $current->id, ['queue_id' => $locked->id, 'from' => $from->value], clinicId: $this->clinicId($locked));
            $this->notify($current, $type, $title, $body);
            $this->realtime->entryChanged($locked, $current);

            return $current;
        });
    }

    /**
     * SKIPPED to WAITING (staff or doctor): back in line from now. arrived_at is kept; queue_sort_at becomes now, so the patient joins the end
     * of their priority group (D10). Only on a queue that is not closed and belongs to today.
     */
    public function returnToQueue(User $user, WalkInQueueEntry $entry): WalkInQueueEntry
    {
        $queue = $entry->queue;
        $this->access->requireActing($user, 'walkins.manage_queue', $queue);

        try {
            return $this->locks->run(function () use ($user, $entry, $queue): WalkInQueueEntry {
                [$locked, $current] = $this->lockBoth($queue, $entry);
                if ($current->status !== EntryStatus::Skipped || ! EntryTransitions::allows($current->status, EntryStatus::Waiting)) {
                    throw InvalidEntryTransition::entry($current->status, 'returned to the queue');
                }
                $this->requireDay($locked);
                $current->forceFill(['status' => EntryStatus::Waiting, 'queue_sort_at' => $this->clock->now()])->save();
                $this->event($locked, $current, 'RETURNED', EntryStatus::Skipped, EntryStatus::Waiting, $user, null, null);
                $this->audit->record('walkins.returned', 'walkin_queue_entry', $current->id, ['queue_id' => $locked->id], clinicId: $this->clinicId($locked));
                $this->realtime->entryChanged($locked, $current);

                return $current;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($entry->patient_profile_id !== null && (str_contains($e->getMessage(), 'uq_one_live_entry_per_patient') || str_contains($e->getMessage(), 'uq_one_held_entry_per_patient'))) {
                $other = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->where('patient_profile_id', $entry->patient_profile_id)
                    ->whereIn('status', array_map(fn (EntryStatus $s) => $s->value, EntryStatus::live()))->where('id', '<>', $entry->id)->value('id');
                if (is_string($other)) {
                    throw new EntryAlreadyLive($other);
                }
            }

            throw $e;
        }
    }

    /** Changes the triage level of a WAITING or SKIPPED entry. The reason is a code; the free note is encrypted on the entry and nowhere else. */
    public function setPriority(User $user, WalkInQueueEntry $entry, PriorityLevel $level, ?PriorityReason $reason, ?string $note): WalkInQueueEntry
    {
        $queue = $entry->queue;
        $this->access->requireActing($user, 'walkins.manage_queue', $queue);

        return $this->locks->run(function () use ($user, $entry, $queue, $level, $reason, $note): WalkInQueueEntry {
            [$locked, $current] = $this->lockBoth($queue, $entry);
            // A guest whose data was purged is a closed record: nobody is left to prioritise.
            if (! in_array($current->status, [EntryStatus::Waiting, EntryStatus::Skipped], true) || $current->guest_pii_purged_at !== null) {
                throw InvalidEntryTransition::entry($current->status, 'given a new priority');
            }
            $this->requireDay($locked);
            $from = $current->triage_priority;
            $newReason = $level->needsReason() ? $reason : null;
            // The note is replaced only when one is sent; a level back to normal drops it with the reason.
            $newNote = $level->needsReason() ? ($note ?? $current->triage_note_encrypted) : null;
            // Same level, reason and note: nothing changed, so no event, no audit row and no realtime hint (no version bump).
            if ($from === $level && $current->triage_reason_code === $newReason && $current->triage_note_encrypted === $newNote) {
                return $current;
            }
            $current->forceFill([
                'triage_priority' => $level,
                'triage_reason_code' => $newReason,
                'triage_note_encrypted' => $newNote,
            ])->save();
            $codes = array_filter(['from' => $from->apiName(), 'to' => $level->apiName(), 'reason_code' => $level->needsReason() ? $reason?->value : null], fn ($v) => $v !== null);
            $this->event($locked, $current, 'PRIORITY_CHANGED', $current->status, $current->status, $user, null, $codes);
            $this->audit->record('walkins.priority_changed', 'walkin_queue_entry', $current->id, ['queue_id' => $locked->id, ...$codes], clinicId: $this->clinicId($locked));
            $this->realtime->entryChanged($locked, $current);

            return $current;
        });
    }

    /**
     * The Phase 5 lock order: the queue row, then the entry (re-read under the lock).
     *
     * @return array{0: WalkInQueue, 1: WalkInQueueEntry} the queue and the entry, both locked
     */
    private function lockBoth(WalkInQueue $queue, WalkInQueueEntry $entry): array
    {
        return [
            WalkInQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail(),
            WalkInQueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail(),
        ];
    }

    /** The local date of another queue's CALLED entry for this doctor, or null. Never returns an id: that row may belong to a location the caller cannot see. */
    private function calledElsewhereDate(string $doctorId): ?string
    {
        $date = WalkInQueueEntry::query()->join('walkin_queues as q', 'q.id', '=', 'walkin_queue_entries.walkin_queue_id')
            ->where('walkin_queue_entries.assigned_doctor_profile_id', $doctorId)->where('walkin_queue_entries.status', EntryStatus::Called->value)
            ->value('q.local_date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    /** DOCTOR_ALREADY_CALLING reason: `previous_day` for a stale CALLED entry of an earlier day, else `other_queue`. Read after a rollback, with no locks. */
    private function doctorCallingReason(string $doctorId, WalkInQueue $queue): string
    {
        $date = $this->calledElsewhereDate($doctorId);

        return $date !== null && $date < $queue->local_date->toDateString() ? 'previous_day' : 'other_queue';
    }

    /** A call or a return needs a queue that is not closed and is today's at its location (a stale queue of an earlier day is never called). */
    private function requireDay(WalkInQueue $queue): void
    {
        if ($queue->status === QueueStatus::Closed) {
            throw new QueueClosedForRegistration('closed');
        }
        $timezone = (string) ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('timezone');
        if ($queue->local_date->toDateString() !== $this->clock->now()->setTimezone($timezone !== '' ? $timezone : (string) config('mediconnect.timezone'))->toDateString()) {
            throw new QueueClosedForRegistration('not_today');
        }
    }

    private function recallLimit(WalkInQueue $queue): int
    {
        $assignment = DoctorClinicAssignment::query()->where('doctor_profile_id', $queue->doctor_profile_id)->where('clinic_location_id', $queue->clinic_location_id)->where('status', 'ACTIVE')->first();

        return WalkInPolicy::fromStored($assignment?->walkin_policy)->recallLimit;
    }

    /** A generic in-app notice to the accounts that may see the entry now (own account and authorized guardians, judged fresh; never a revoked guardian). A guest has none. */
    private function notify(WalkInQueueEntry $entry, string $type, string $title, string $body): void
    {
        if ($entry->patient_profile_id === null) {
            return;
        }
        foreach ($this->patients->userIds($entry->patient_profile_id) as $userId) {
            // In-app only: an operational notice, no push (a lock screen must not show a queue call).
            $this->notifications->notify($userId, $type, $title, $body, ['entry_id' => $entry->id], push: false);
        }
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
