<?php

namespace App\Modules\Presence\DTOs;

use Carbon\CarbonImmutable;

/**
 * Why a check-in is not allowed right now (increment 5.9-B). Produced by CheckInEligibility; the assert methods turn it into a 422, and the
 * advisory probe returns it as data.
 */
final class CheckInRefusal
{
    /**
     * @param  string  $reason  machine-readable code, see CheckInRefused
     * @param  CarbonImmutable|null  $opensAt  the UTC instant a check-in would next be allowed, when known
     * @param  string|null  $opensShiftId  the shift whose window opens at $opensAt, or null when clinic hours do
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $field,
        public readonly string $message,
        public readonly ?CarbonImmutable $opensAt = null,
        public readonly ?string $opensShiftId = null,
    ) {}
}
