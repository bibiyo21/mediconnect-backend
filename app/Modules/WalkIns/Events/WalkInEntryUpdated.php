<?php

namespace App\Modules\WalkIns\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use App\Modules\WalkIns\Services\PatientAccess;

/**
 * A patient's own entry changed status (decision 267). One event per account that may see the entry NOW, on that account's
 * `presence-user.{userId}` (single member by construction). Entry id, status, version and time only.
 *
 * The audience is checked again when the event is PUBLISHED (channels()): the profile id travels as a property, never in the payload, and if the
 * recipient is no longer the profile's own user or an authorised guardian (a revocation between queueing and publishing) the event goes to no channel.
 */
final class WalkInEntryUpdated extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['entry_id', 'status', 'queue_version', 'occurred_at'];

    public function __construct(
        public readonly string $userId,
        public readonly string $patientProfileId,
        public readonly string $entryId,
        public readonly string $status,
        public readonly int $queueVersion,
        public readonly string $occurredAt,
    ) {}

    protected function channels(): array
    {
        // A fresh check on every publish (no per-request memo: this runs in a queue worker).
        if (! in_array($this->userId, app(PatientAccess::class)->userIds($this->patientProfileId), true)) {
            return [];
        }

        return [Channels::user($this->userId)];
    }

    protected function payload(): array
    {
        return [
            'entry_id' => $this->entryId,
            'status' => $this->status,
            'queue_version' => $this->queueVersion,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
