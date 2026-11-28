<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily sweep of walk-in queues from an earlier local day (increment 5.8, D-O; owner OQ-A: auto-close and cancel, never CALLED or
 * IN_CONSULTATION, and alert). For each open or paused queue whose local date is before today at its location:
 *  - every REGISTERED, WAITING or SKIPPED entry is cancelled with END_OF_DAY, system actor, in-app notice only (no push);
 *  - a CALLED or IN_CONSULTATION entry is never touched. If one is left, the queue stays as it is and a PAST_DAY_QUEUE_NEEDS_ATTENTION alert
 *    is logged with ids and counts only; the doctor or staff resolve it through the existing runbook. Otherwise the queue is CLOSED.
 * Each queue runs in its own transaction, locking the queue, then its entries in ascending id order. Idempotent: a closed queue is skipped.
 * A queue whose doctor still holds an open session at that location that began on the queue's local day is skipped (5.8 m4): the doctor is
 * working past midnight, so the automatic check-out closes the session first and a later run sweeps. One failing queue never stops the others
 * (5.8 m3): it is logged with its id and the exception class, and the run goes on.
 */
final class PastDaySweep
{
    public function __construct(
        private readonly EntryActions $entries,
        private readonly RecordsAudit $audit,
        private readonly QueueRealtime $realtime,
        private readonly Clock $clock,
        private readonly LockWait $locks,
    ) {}

    /** @return int the number of queues closed */
    public function run(CarbonImmutable $now): int
    {
        $closed = 0;
        foreach ($this->candidates($now) as $queueId) {
            try {
                if ($this->sweepQueue($queueId)) {
                    $closed++;
                }
            } catch (Throwable $e) {
                Log::error('PAST_DAY_QUEUE_SWEEP_FAILED', ['walkin_queue_id' => $queueId, 'exception' => $e::class]);
            }
        }

        return $closed;
    }

    /**
     * Open or paused queues whose local date (in their location's own timezone) is before today there. Dates are Y-m-d, so a string comparison is a date comparison.
     *
     * @return list<string>
     */
    private function candidates(CarbonImmutable $now): array
    {
        $rows = DB::table('walkin_queues as q')->join('clinic_locations as l', 'l.id', '=', 'q.clinic_location_id')
            ->whereIn('q.status', [QueueStatus::Open->value, QueueStatus::Paused->value])
            ->orderBy('q.id')->get(['q.id', 'q.local_date', 'l.timezone']);

        $ids = [];
        foreach ($rows as $row) {
            $timezone = is_string($row->timezone) && $row->timezone !== '' ? $row->timezone : (string) config('mediconnect.timezone');
            if ((string) $row->local_date < $now->setTimezone($timezone)->toDateString()) {
                $ids[] = (string) $row->id;
            }
        }

        return $ids;
    }

    private function sweepQueue(string $queueId): bool
    {
        return $this->locks->run(fn (): bool => DB::transaction(function () use ($queueId): bool {
            $queue = WalkInQueue::query()->whereKey($queueId)->lockForUpdate()->first();
            if ($queue === null || ! in_array($queue->status, [QueueStatus::Open, QueueStatus::Paused], true)) {
                return false;
            }

            $stillIn = $this->openSessionOfThatDay($queue);
            if ($stillIn !== null) {
                Log::info('PAST_DAY_QUEUE_SWEEP_SKIPPED', [
                    'walkin_queue_id' => (string) $queue->id,
                    'doctor_presence_session_id' => $stillIn,
                    'clinic_location_id' => (string) $queue->clinic_location_id,
                    'reason_code' => 'doctor_still_in',
                ]);

                return false;
            }

            $entries = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->orderBy('id')->lockForUpdate()->get();
            $held = $entries->filter(fn (WalkInQueueEntry $e) => in_array($e->status, [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Skipped], true));
            $active = $entries->filter(fn (WalkInQueueEntry $e) => in_array($e->status, [EntryStatus::Called, EntryStatus::InConsultation], true));

            foreach ($held as $entry) {
                $this->entries->cancelUnderLock($queue, $entry, CancelReason::EndOfDay, 'SYSTEM', null, [], notify: true, push: false);
            }

            if ($active->isNotEmpty()) {
                Log::warning('PAST_DAY_QUEUE_NEEDS_ATTENTION', [
                    'walkin_queue_id' => (string) $queue->id,
                    'clinic_location_id' => (string) $queue->clinic_location_id,
                    'called' => $active->where('status', EntryStatus::Called)->count(),
                    'in_consultation' => $active->where('status', EntryStatus::InConsultation)->count(),
                ]);
            } else {
                $this->close($queue, $held->count());
            }
            $this->realtime->queueChanged($queue);

            return $active->isEmpty();
        }));
    }

    /**
     * The id of an open session of the queue's doctor at its location that began on the queue's local day, or null. Only ids are returned.
     * It is read without a lock: the auto check-out takes the session lock before the queue lock, so this sweep must not wait for it.
     */
    private function openSessionOfThatDay(WalkInQueue $queue): ?string
    {
        if ($queue->doctor_profile_id === null) {
            return null;
        }
        $timezone = (string) DB::table('clinic_locations')->where('id', $queue->clinic_location_id)->value('timezone');
        $timezone = $timezone !== '' ? $timezone : (string) config('mediconnect.timezone');

        $session = DB::table('doctor_presence_sessions')
            ->where('doctor_profile_id', $queue->doctor_profile_id)->where('clinic_location_id', $queue->clinic_location_id)->whereNull('checked_out_at')
            ->orderBy('id')->get(['id', 'checked_in_at'])
            ->first(fn ($row) => CarbonImmutable::parse((string) $row->checked_in_at)->setTimezone($timezone)->toDateString() === $queue->local_date->toDateString());

        return $session === null ? null : (string) $session->id;
    }

    private function close(WalkInQueue $queue, int $cancelled): void
    {
        $from = $queue->status;
        $now = $this->clock->now();
        $queue->forceFill(['status' => QueueStatus::Closed, 'closed_at' => $now, 'closed_by' => null])->save();
        DB::table('walkin_queue_events')->insert([
            'walkin_queue_id' => $queue->id, 'walkin_queue_entry_id' => null, 'event_type' => 'QUEUE_CLOSED', 'from_status' => null, 'to_status' => null,
            'actor_id' => null, 'reason' => null, 'metadata' => json_encode(['from' => $from->value, 'to' => QueueStatus::Closed->value, 'entries_cancelled' => $cancelled, 'by' => 'SYSTEM', 'cause' => 'END_OF_DAY']),
        ]);
        $this->audit->record('walkins.queue_closed', 'walkin_queue', $queue->id, ['from' => $from->value, 'to' => QueueStatus::Closed->value, 'entries_cancelled' => $cancelled, 'by' => 'SYSTEM'], clinicId: $this->clinicIdOf($queue));
    }

    private function clinicIdOf(WalkInQueue $queue): ?string
    {
        $id = ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('clinic_id');

        return is_string($id) ? $id : null;
    }
}
