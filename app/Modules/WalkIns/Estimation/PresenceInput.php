<?php

namespace App\Modules\WalkIns\Estimation;

use App\Modules\Presence\Enums\PresenceStatus;
use Carbon\CarbonImmutable;

/**
 * What is true about the doctor now, and when they are next expected back.
 *
 * resumesAt is the break's expected end (ON_BREAK), the announced return (TEMPORARILY_UNAVAILABLE),
 * or the start of the next shift (NOT_CHECKED_IN, OUT). It is a fixed instant: the earliest the doctor
 * can be back, so the estimator never counts it earlier, only allows for lateness. Null means nobody
 * knows: the estimate is "unknown".
 */
final class PresenceInput
{
    public function __construct(
        public readonly PresenceStatus $status,
        public readonly ?CarbonImmutable $resumesAt = null,
    ) {}

    /** The doctor is at work: nothing to wait for before the first call. */
    public function isAtWork(): bool
    {
        return in_array($this->status, [PresenceStatus::In, PresenceStatus::Busy], true);
    }
}
