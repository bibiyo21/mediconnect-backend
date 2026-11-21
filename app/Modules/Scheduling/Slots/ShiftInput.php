<?php

namespace App\Modules\Scheduling\Slots;

/** One recurring shift with everything the generator needs (local wall-clock times). */
final class ShiftInput
{
    /** @param list<array{string, string}> $breaks local [start, end] pairs, "HH:MM" */
    public function __construct(
        public readonly string $id,
        public readonly int $dayOfWeek,
        public readonly ?string $locationId,
        public readonly string $modeId,
        public readonly string $serviceMode,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $timezone,
        public readonly int $durationMinutes,
        public readonly int $bufferMinutes,
        public readonly ?int $maxAppointments,
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveUntil,
        public readonly array $breaks = [],
    ) {}
}
