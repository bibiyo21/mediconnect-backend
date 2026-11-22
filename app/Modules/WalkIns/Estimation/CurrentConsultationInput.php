<?php

namespace App\Modules\WalkIns\Estimation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** The consultation running right now. */
final class CurrentConsultationInput
{
    /** No consultation is expected to take more than a day; larger values are bad data. */
    public const MAX_SECONDS = 86400;

    public function __construct(
        public readonly CarbonImmutable $startedAt,
        public readonly int $expectedDurationSeconds,
        public readonly DurationSource $durationSource,
    ) {
        if ($expectedDurationSeconds < 1 || $expectedDurationSeconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Expected duration must be positive.');
        }
    }
}
