<?php

namespace Tests\Support;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;

/** A stand-in broadcast event: the real ones arrive with the modules that emit them. */
final class TestRealtimeEvent extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['location_id', 'waiting'];

    public function __construct(public readonly string $locationId, public readonly int $waiting) {}

    protected function channels(): array
    {
        return [Channels::clinicQueue($this->locationId)];
    }

    protected function payload(): array
    {
        return ['location_id' => $this->locationId, 'waiting' => $this->waiting];
    }
}
