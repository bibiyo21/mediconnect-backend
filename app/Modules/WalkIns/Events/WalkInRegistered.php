<?php

namespace App\Modules\WalkIns\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;

/**
 * A walk-in entry joined a queue (§19 WalkInRegistered). A hint for the location's queue staff on `presence-clinic.{location}.queue`
 * and the queue doctor's own `presence-doctor.{id}`: ids, the queue label, status and counts. Never a name, phone, HMO text or note.
 */
final class WalkInRegistered extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['queue_id', 'clinic_location_id', 'doctor_profile_id', 'entry_id', 'queue_label', 'status', 'waiting_count', 'queue_version', 'occurred_at'];

    public function __construct(
        public readonly string $queueId,
        public readonly string $clinicLocationId,
        public readonly ?string $doctorProfileId,
        public readonly string $entryId,
        public readonly string $queueLabel,
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
            'entry_id' => $this->entryId,
            'queue_label' => $this->queueLabel,
            'status' => $this->status,
            'waiting_count' => $this->waitingCount,
            'queue_version' => $this->queueVersion,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
