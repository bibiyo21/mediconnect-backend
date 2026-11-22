<?php

namespace App\Modules\Presence\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use Carbon\CarbonImmutable;

/**
 * A doctor went on break (§19 DoctorBreakStarted), on `private-clinic.{location}`. Carries the
 * expected end so the staff view can show "back at ..." (an estimate); no reason or actor.
 */
final class DoctorBreakStarted extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['doctor_profile_id', 'clinic_location_id', 'session_id', 'break_expected_end_at', 'version'];

    public function __construct(
        public readonly string $doctorProfileId,
        public readonly string $clinicLocationId,
        public readonly string $sessionId,
        public readonly CarbonImmutable $expectedEndAt,
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
            'break_expected_end_at' => $this->expectedEndAt->toIso8601ZuluString(),
            'version' => $this->version,
        ];
    }
}
