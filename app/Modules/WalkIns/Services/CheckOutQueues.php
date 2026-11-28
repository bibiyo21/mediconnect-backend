<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\WalkIns\Contracts\CheckOutQueues as CheckOutQueuesContract;
use App\Modules\WalkIns\DTOs\CheckOutQueueFacts;
use App\Modules\WalkIns\DTOs\CheckOutQueueResult;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type EntryRow from CheckOutQueueFacts
 *
 * The doctor's queues at one location for one local day, locked and changed on check-out (increment 5.8, D-B and D-D). Lock order: the queue
 * rows (ascending id) before their entries (ascending id), after the caller's session lock. A held entry is REGISTERED, WAITING, SKIPPED or
 * (for a block) CALLED or IN_CONSULTATION; only the first three are ever changed here. Queue rows are PAUSED, never CLOSED (close is 5.8-2).
 */
final class CheckOutQueues implements CheckOutQueuesContract
{
    public function __construct(
        private readonly QueueRealtime $realtime,
        private readonly EntryActions $entries,
        private readonly RecordsAudit $audit,
        private readonly SendsNotifications $notifications,
        private readonly PatientAccess $patients,
    ) {}

    public function lockForCheckOut(string $doctorProfileId, string $locationId, string $localDate): CheckOutQueueFacts
    {
        [$queueIds, $doctorQueueIds] = $this->queueIds($doctorProfileId, $locationId, $localDate);

        $queues = WalkInQueue::query()->whereIn('id', $queueIds)->orderBy('id')->lockForUpdate()->get();
        $entries = $this->entryQuery($doctorProfileId, $queueIds, $doctorQueueIds)->orderBy('id')->lockForUpdate()->get();

        return $this->facts($doctorProfileId, $locationId, $localDate, $queues, $entries, $doctorQueueIds);
    }

    public function peek(string $doctorProfileId, string $locationId, string $localDate): CheckOutQueueFacts
    {
        [$queueIds, $doctorQueueIds] = $this->queueIds($doctorProfileId, $locationId, $localDate);

        $queues = WalkInQueue::query()->whereIn('id', $queueIds)->orderBy('id')->get();
        $entries = $this->entryQuery($doctorProfileId, $queueIds, $doctorQueueIds)->orderBy('id')->get();

        return $this->facts($doctorProfileId, $locationId, $localDate, $queues, $entries, $doctorQueueIds);
    }

    public function apply(CheckOutQueueFacts $facts, CheckOutDisposition $disposition, ?string $actorUserId, bool $guestsInformed): CheckOutQueueResult
    {
        if ($disposition === CheckOutDisposition::NotifyAndCancel && $facts->guestDispositionableCount() > 0 && ! $guestsInformed) {
            throw ValidationException::withMessages(['guests_informed_in_person' => ['Tell each guest in person, then confirm it here: a guest has no notification.']]);
        }

        $byDoctor = $actorUserId !== null && DB::table('doctor_profiles')->where('id', $facts->doctorProfileId)->value('user_id') === $actorUserId;
        $by = $actorUserId === null ? 'SYSTEM' : ($byDoctor ? 'DOCTOR' : 'STAFF');

        $paused = $this->pauseOpenQueues($facts, $actorUserId);

        $held = 0;
        $cancelled = 0;
        if ($disposition === CheckOutDisposition::HoldForReassignment) {
            foreach ($facts->dispositionable() as $entry) {
                $held++;
                if ($entry['patient_profile_id'] !== null) {
                    $this->notifyPatient($entry['patient_profile_id'], 'walkins.doctor_checked_out', 'The doctor has left the clinic', 'Please ask the clinic desk for advice. Your place is kept for now.', ['entry_id' => $entry['id']]);
                }
            }
        } else {
            foreach ($facts->dispositionable() as $row) {
                $entry = WalkInQueueEntry::query()->whereKey($row['id'])->firstOrFail();
                $queue = WalkInQueue::query()->whereKey($entry->walkin_queue_id)->firstOrFail();
                // A guest has no notification channel: the staff confirmation is recorded on the event instead.
                $metadata = $entry->patient_profile_id === null ? ['patient_informed_in_person' => $guestsInformed] : [];
                $this->entries->cancelUnderLock($queue, $entry, CancelReason::DoctorCheckedOut, $by, $actorUserId, $metadata, notify: true);
                $cancelled++;
            }
        }

        return new CheckOutQueueResult($paused, $held, $cancelled);
    }

    /**
     * The doctor's own queues for the day, plus every queue, on any date, that holds one of this doctor's CALLED or IN_CONSULTATION entries
     * (a shared service pool, or a stale CALLED left from an earlier day: 5.8 M2). A stale CALLED blocks the check-out, so its queue must be
     * locked too. Returns [all ids, the doctor's own ids], each ascending.
     *
     * @return array{list<string>, list<string>}
     */
    private function queueIds(string $doctorProfileId, string $locationId, string $localDate): array
    {
        $own = array_values(WalkInQueue::query()->where('clinic_location_id', $locationId)->where('local_date', $localDate)
            ->where('doctor_profile_id', $doctorProfileId)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all());

        $pool = array_values(WalkInQueueEntry::query()
            ->where('assigned_doctor_profile_id', $doctorProfileId)
            ->whereIn('status', [EntryStatus::Called->value, EntryStatus::InConsultation->value])
            ->pluck('walkin_queue_id')->map(fn ($id) => (string) $id)->all());

        $all = array_values(array_unique([...$own, ...$pool]));
        sort($all, SORT_STRING);

        return [$all, $own];
    }

    /**
     * Held entries of the doctor's own queues, and this doctor's CALLED or IN_CONSULTATION entries in any queue of the day.
     *
     * @param  list<string>  $queueIds
     * @param  list<string>  $doctorQueueIds
     * @return Builder<WalkInQueueEntry>
     */
    private function entryQuery(string $doctorProfileId, array $queueIds, array $doctorQueueIds): Builder
    {
        return WalkInQueueEntry::query()
            ->whereIn('walkin_queue_id', $queueIds)
            ->where(function ($w) use ($doctorProfileId, $doctorQueueIds): void {
                $w->where(fn ($q) => $q->whereIn('walkin_queue_id', $doctorQueueIds)
                    ->whereIn('status', array_map(fn (EntryStatus $s) => $s->value, EntryStatus::held())))
                    ->orWhere(fn ($q) => $q->where('assigned_doctor_profile_id', $doctorProfileId)
                        ->whereIn('status', [EntryStatus::Called->value, EntryStatus::InConsultation->value]));
            });
    }

    /**
     * @param  Collection<int, WalkInQueue>  $queues
     * @param  Collection<int, WalkInQueueEntry>  $entries
     * @param  list<string>  $doctorQueueIds
     */
    private function facts(string $doctorProfileId, string $locationId, string $localDate, Collection $queues, Collection $entries, array $doctorQueueIds): CheckOutQueueFacts
    {
        $queueRows = [];
        foreach ($queues as $queue) {
            $id = (string) $queue->id;
            $mine = $entries->filter(fn (WalkInQueueEntry $e) => (string) $e->walkin_queue_id === $id);
            $queueRows[] = [
                'id' => $id,
                'status' => $queue->status->value,
                'doctor_queue' => in_array($id, $doctorQueueIds, true),
                'at_location' => (string) $queue->clinic_location_id === $locationId,
                'registered' => $this->countStatus($mine, EntryStatus::Registered),
                'waiting' => $this->countStatus($mine, EntryStatus::Waiting),
                'called' => $this->countStatus($mine, EntryStatus::Called),
                'skipped' => $this->countStatus($mine, EntryStatus::Skipped),
                'in_consultation' => $this->countStatus($mine, EntryStatus::InConsultation),
                'guests' => $mine->filter(fn (WalkInQueueEntry $e) => $e->patient_profile_id === null && in_array($e->status, [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Skipped], true))->count(),
            ];
        }

        $entryRows = [];
        foreach ($entries as $entry) {
            $entryRows[] = [
                'id' => (string) $entry->id,
                'queue_id' => (string) $entry->walkin_queue_id,
                'status' => $entry->status,
                'patient_profile_id' => $entry->patient_profile_id,
                'doctor_queue' => in_array((string) $entry->walkin_queue_id, $doctorQueueIds, true),
            ];
        }

        return new CheckOutQueueFacts($doctorProfileId, $locationId, $localDate, $queueRows, $entryRows);
    }

    /** OPEN doctor queues become PAUSED (no new registrations); a PAUSED queue stays so, a CLOSED one is left as it is. */
    private function pauseOpenQueues(CheckOutQueueFacts $facts, ?string $actorUserId): int
    {
        $paused = 0;
        foreach ($facts->queues as $row) {
            if (! $row['doctor_queue'] || $row['status'] !== QueueStatus::Open->value) {
                continue;
            }
            $queue = WalkInQueue::query()->whereKey($row['id'])->firstOrFail();
            $queue->forceFill(['status' => QueueStatus::Paused])->save();
            DB::table('walkin_queue_events')->insert([
                'walkin_queue_id' => $queue->id, 'walkin_queue_entry_id' => null, 'event_type' => 'QUEUE_PAUSED', 'from_status' => null, 'to_status' => null,
                'actor_id' => $actorUserId, 'reason' => null, 'metadata' => json_encode(['from' => QueueStatus::Open->value, 'to' => QueueStatus::Paused->value, 'cause' => 'DOCTOR_CHECKED_OUT']),
            ]);
            $this->audit->record('walkins.queue_paused', 'walkin_queue', $queue->id, ['from' => QueueStatus::Open->value, 'to' => QueueStatus::Paused->value, 'cause' => 'DOCTOR_CHECKED_OUT'], clinicId: $this->clinicIdOf($queue));
            $this->realtime->queueChanged($queue);
            $paused++;
        }

        return $paused;
    }

    /** @param  Collection<int, WalkInQueueEntry>  $entries */
    private function countStatus(Collection $entries, EntryStatus $status): int
    {
        return $entries->filter(fn (WalkInQueueEntry $e) => $e->status === $status)->count();
    }

    /** @param  array<string, string>  $data  ids only: the generic notice names no patient detail */
    private function notifyPatient(string $patientProfileId, string $type, string $title, string $body, array $data = []): void
    {
        foreach ($this->patients->userIds($patientProfileId) as $userId) {
            $this->notifications->notify($userId, $type, $title, $body, $data);
        }
    }

    private function clinicIdOf(WalkInQueue $queue): ?string
    {
        $id = DB::table('clinic_locations')->where('id', $queue->clinic_location_id)->value('clinic_id');

        return is_string($id) ? $id : null;
    }
}
