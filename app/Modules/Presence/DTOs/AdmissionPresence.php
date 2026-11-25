<?php

namespace App\Modules\Presence\DTOs;

use App\Modules\Presence\Enums\PresenceStatus;
use Carbon\CarbonImmutable;

/**
 * A doctor's presence at one location as walk-in admission needs it (decision 179). The status is relative to the location:
 * a doctor who is IN at another location, or online, is NOT_CHECKED_IN here. OUT means checked out here today and not
 * checked in again. $breakEndsAt is set only while ON_BREAK (an expectation, not a promise).
 */
final class AdmissionPresence
{
    public function __construct(
        public readonly PresenceStatus $status,
        public readonly ?CarbonImmutable $breakEndsAt = null,
        public readonly ?string $sessionId = null,
    ) {}
}
