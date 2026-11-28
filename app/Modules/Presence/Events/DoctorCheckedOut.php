<?php

namespace App\Modules\Presence\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use App\Modules\Presence\Enums\PresenceStatus;

/**
 * A doctor checked out of the location (§19 DoctorCheckedOut). A hint for the location's staff on `presence-clinic.{location}`: the same
 * whitelist as DoctorCheckedIn. No reason, actor, device or time (CLAUDE.md §19, increment 5.8 gate point 7).
 */
final class DoctorCheckedOut extends RealtimeEvent
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
