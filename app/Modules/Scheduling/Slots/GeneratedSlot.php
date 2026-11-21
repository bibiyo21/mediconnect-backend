<?php

namespace App\Modules\Scheduling\Slots;

final class GeneratedSlot
{
    public function __construct(
        public readonly string $modeId,
        public readonly ?string $shiftId,
        public readonly ?string $locationId,
        public readonly string $kind,
        public readonly int $startsAt,
        public readonly int $endsAt,
        public readonly string $localDate,
    ) {}
}
