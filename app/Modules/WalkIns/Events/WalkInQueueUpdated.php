<?php

namespace App\Modules\WalkIns\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;

/**
 * Something in a queue changed (§19 WalkInQueueUpdated): clients refetch the queue. Same channels as WalkInRegistered.
 * Ids, the queue status, a count and the version only.
 */
final class WalkInQueueUpdated extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['queue_id', 'clinic_location_id', 'doctor_profile_id', 'status', 'waiting_count', 'queue_version', 'occurred_at'];

    public function __construct(
        public readonly string $queueId,
        public readonly string $clinicLocationId,
        public readonly ?string $doctorProfileId,
        public readonly string $status,
        public readonly int $waitingCount,
        public readonly int $queueVersion,
        public readonly string $occurredAt,
    ) {}

    protected function channels(): array
    {
        $channels = [Channels::clinicQueue($this->clinicLocationId)];
        if ($this->doctorProfileId !== null) {
            $channels[] = Channels::doctor($this->doctorProfileId);
        }

        return $channels;
    }

    protected function payload(): array
    {
        return [
            'queue_id' => $this->queueId,
            'clinic_location_id' => $this->clinicLocationId,
            'doctor_profile_id' => $this->doctorProfileId,
            'status' => $this->status,
            'waiting_count' => $this->waitingCount,
            'queue_version' => $this->queueVersion,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
