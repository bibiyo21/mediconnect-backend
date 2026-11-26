<?php

namespace App\Modules\WalkIns\Realtime;

use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Events\WalkInEntryUpdated;
use App\Modules\WalkIns\Events\WalkInQueueUpdated;
use App\Modules\WalkIns\Events\WalkInRegistered;
use App\Modules\WalkIns\Jobs\EmitQueueUpdated;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\PatientAccess;
use App\Modules\WalkIns\Services\QueueLabel;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real-time hints for walk-ins (decisions 266-270). Everything runs in DB::afterCommit, so nothing is sent for a rolled-back change
 * and the state read (version, count, audience) is the committed one. Hints carry ids, labels, statuses and counts only (RealtimeEvent
 * enforces it); clients refetch the REST API, which stays the truth.
 *
 * - The audience of an entry hint is resolved here, at emit time, from the profile and its guardians authorised NOW (PatientAccess, never the
 *   per-request WalkInAccess memo, never the registrant by shortcut). A guest has no account and gets no patient hint.
 * - Queue-level hints are debounced: at most one per queue per window, and a change inside the window is sent once at its end (trailing).
 */
final class QueueRealtime
{
    public function __construct(
        private readonly PatientAccess $patients,
        private readonly Clock $clock,
    ) {}

    /** A new entry: staff and doctor hints, the patient's own hint, and a (debounced) queue hint. */
    public function registered(WalkInQueue $queue, WalkInQueueEntry $entry): void
    {
        $queueId = $queue->id;
        $entryId = $entry->id;
        DB::afterCommit(fn () => $this->safely($queueId, function () use ($queueId, $entryId): void {
            $queue = WalkInQueue::query()->find($queueId);
            $entry = WalkInQueueEntry::query()->find($entryId);
            if ($queue === null || $entry === null) {
                return;
            }
            $now = $this->stamp();
            $version = $this->version($queue->id);
            WalkInRegistered::dispatch(
                $queue->id, $queue->clinic_location_id, $queue->doctor_profile_id, $entry->id,
                QueueLabel::of($queue->number_prefix, $entry->queue_number), $entry->status->value, $this->waiting($queue->id), $version, $now,
            );
            $this->entryHint($entry, $version, $now);
        }));
    }

    /** An entry changed status: the patient's hint (audience now) and a debounced queue hint. */
    public function entryChanged(WalkInQueue $queue, WalkInQueueEntry $entry): void
    {
        $queueId = $queue->id;
        $entryId = $entry->id;
        DB::afterCommit(fn () => $this->safely($queueId, function () use ($queueId, $entryId): void {
            $entry = WalkInQueueEntry::query()->find($entryId);
            if ($entry !== null) {
                $this->entryHint($entry, $this->version($queueId), $this->stamp());
            }
            $this->debouncedQueueHint($queueId);
        }));
    }

    /** The queue itself changed (paused, opened, a status moved): a debounced queue hint. */
    public function queueChanged(WalkInQueue $queue): void
    {
        $queueId = $queue->id;
        DB::afterCommit(fn () => $this->safely($queueId, fn () => $this->debouncedQueueHint($queueId)));
    }

    /** Leading edge now; a change inside the window schedules one trailing hint at its end. Used by the job too. */
    public function debouncedQueueHint(string $queueId): void
    {
        $window = max(1, (int) config('walkins.realtime.debounce_seconds', 2));
        if (Cache::add("walkin:rt:lead:{$queueId}", 1, $window)) {
            $this->emitQueueHint($queueId);

            return;
        }
        // On the sync queue a delayed job would run at once and defeat the debounce, so there the trailing hint is skipped (clients refetch on the next change).
        if (config('queue.default') === 'sync') {
            return;
        }
        if (Cache::add("walkin:rt:trail:{$queueId}", 1, $window)) {
            EmitQueueUpdated::dispatch($queueId)->delay(now()->addSeconds($window));
        }
    }

    /** Sends the queue hint with the committed state now (the trailing job calls this). */
    public function emitQueueHint(string $queueId): void
    {
        $queue = WalkInQueue::query()->find($queueId);
        if ($queue === null) {
            return;
        }
        WalkInQueueUpdated::dispatch($queue->id, $queue->clinic_location_id, $queue->doctor_profile_id, $queue->status->value, $this->waiting($queue->id), $this->version($queue->id), $this->stamp());
    }

    private function entryHint(WalkInQueueEntry $entry, int $version, string $now): void
    {
        if ($entry->patient_profile_id === null) {
            return;
        }
        foreach ($this->patients->userIds($entry->patient_profile_id) as $userId) {
            WalkInEntryUpdated::dispatch($userId, $entry->patient_profile_id, $entry->id, $entry->status->value, $version, $now);
        }
    }

    /** A hint must never break a committed write: log the queue id and the exception class only, and drop the hint (clients refetch on reconnect). */
    private function safely(string $queueId, \Closure $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('walkins.realtime_hint_dropped', ['queue_id' => $queueId, 'exception' => $e::class]);
        }
    }

    /**
     * Monotonic per queue: the number of events written for it (append-only table). An ordering hint only: a client that holds a version
     * refetches whenever a hint arrives with a version GREATER THAN OR EQUAL to it (equal can still follow a missed hint), never trusts it as a state.
     */
    private function version(string $queueId): int
    {
        return DB::table('walkin_queue_events')->where('walkin_queue_id', $queueId)->count();
    }

    private function waiting(string $queueId): int
    {
        return WalkInQueueEntry::query()->where('walkin_queue_id', $queueId)
            ->whereIn('status', [EntryStatus::Registered->value, EntryStatus::Waiting->value, EntryStatus::Called->value])->count();
    }

    private function stamp(): string
    {
        return $this->clock->now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
