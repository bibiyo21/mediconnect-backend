<?php

namespace App\Modules\Scheduling\Slots;

/** A clinic holiday on a local date: closed, or open only at special hours. */
final class HolidayInput
{
    public function __construct(
        public readonly ?string $locationId,
        public readonly string $date,
        public readonly bool $isClosed,
        public readonly ?string $opensAt = null,
        public readonly ?string $closesAt = null,
    ) {}
}
