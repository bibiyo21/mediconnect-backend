<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\WalkIns\Contracts\QueueActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reads the append-only queue event log: the newest occurred_at of an activity event on the doctor's queues at one location. */
final class QueueActivityLog implements QueueActivity
{
    /** Events that mean a person is working the queue. Metadata-only events (pause, close, transfer, priority) do not count. */
    private const ACTIVITY = ['REGISTERED', 'ARRIVED', 'CALLED', 'RECALLED', 'STARTED', 'COMPLETED', 'SKIPPED', 'NO_SHOW'];

    public function lastActivityAt(string $doctorProfileId, string $locationId): ?CarbonImmutable
    {
        $at = DB::table('walkin_queue_events as e')
            ->join('walkin_queues as q', 'q.id', '=', 'e.walkin_queue_id')
            ->where('q.doctor_profile_id', $doctorProfileId)
            ->where('q.clinic_location_id', $locationId)
            ->whereIn('e.event_type', self::ACTIVITY)
            ->max('e.occurred_at');

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }
}
