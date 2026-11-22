<?php

namespace App\Modules\WalkIns\Estimation;

/** How long a consultation is expected to take, and why we think so. */
final class ExpectedDuration
{
    public function __construct(
        public readonly int $seconds,
        public readonly DurationSource $source,
        /** How many completed consultations the figure rests on (0 unless Rolling). */
        public readonly int $samples = 0,
    ) {}
}
