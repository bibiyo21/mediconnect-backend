<?php

namespace App\Modules\Presence\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;

/** A doctor came back from a break (§19 DoctorBreakEnded), on `presence-clinic.{location}`; ids and the version only. */
final class DoctorBreakEnded extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['doctor_profile_id', 'clinic_location_id', 'session_id', 'version'];

    public function __construct(
        public readonly string $doctorProfileId,
        public readonly string $clinicLocationId,
        public readonly string $sessionId,
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
            'version' => $this->version,
        ];
    }
}
