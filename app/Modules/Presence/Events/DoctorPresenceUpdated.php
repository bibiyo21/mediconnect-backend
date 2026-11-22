<?php

namespace App\Modules\Presence\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use App\Modules\Presence\Enums\PresenceStatus;

/**
 * A doctor's stored presence status changed (§19 DoctorPresenceUpdated).
 * A hint for the location's staff on `presence-clinic.{location}`: ids, the stored status and the
 * session version only. No reason, actor, device or time of day (phase 5 plan Q13, CLAUDE.md §19).
 */
final class DoctorPresenceUpdated extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['doctor_profile_id', 'clinic_location_id', 'session_id', 'status', 'version'];

    public function __construct(
        public readonly string $doctorProfileId,
        public readonly string $clinicLocationId,
        public readonly string $sessionId,
        public readonly PresenceStatus $status,
        public readonly int $version,
    ) {}

    protected function channels(): array
    {
        return [Channels::clinic($this->clinicLocationId)];
    }

    protected function payload(): array
    {
        return [
            'doctor_profile_id' => $this->doctorProfileId,
            'clinic_location_id' => $this->clinicLocationId,
            'session_id' => $this->sessionId,
            'status' => $this->status->value,
            'version' => $this->version,
        ];
    }
}
