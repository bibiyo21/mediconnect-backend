<?php

namespace App\Modules\WalkIns\Estimation;

use InvalidArgumentException;

/** The estimator's tuning. A plain value so the estimator itself never reads config. */
final class EstimatorSettings
{
    public function __construct(
        /** Each uncertain consultation duration runs from (100 - this)% to (100 + this)%. */
        public readonly int $spreadPercent = 25,
        /** No estimate is narrower than this. */
        public readonly int $minRangeWidthMinutes = 5,
        /** The most lateness allowed on a fixed return time (break end, announced return, shift start), beyond the minimum width. */
        public readonly int $maxLatenessMinutes = 30,
    ) {
        if ($spreadPercent < 1 || $spreadPercent > 90 || $minRangeWidthMinutes < 1 || $maxLatenessMinutes < $minRangeWidthMinutes) {
            throw new InvalidArgumentException('Estimator settings out of range.');
        }
    }

    public static function fromConfig(): self
    {
        $c = EstimationConfig::fromConfig();

        return new self($c->spreadPercent, $c->minRangeWidthMinutes, $c->maxLatenessMinutes);
    }
}
