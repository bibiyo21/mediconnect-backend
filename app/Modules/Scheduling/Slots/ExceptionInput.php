<?php

namespace App\Modules\Scheduling\Slots;

/**
 * A schedule exception as UTC seconds. Added hours carry their service
 * mode, slot length and buffer (from the service).
 */
final class ExceptionInput
{
    public function __construct(
        public readonly string $type,
        public readonly string $effect,
        public readonly ?string $locationId,
        public readonly int $startsAt,
        public readonly int $endsAt,
        public readonly ?string $modeId = null,
        public readonly ?string $serviceMode = null,
        public readonly int $durationMinutes = 0,
        public readonly int $bufferMinutes = 0,
        public readonly string $timezone = 'Asia/Manila',
    ) {}
}
