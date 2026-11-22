<?php

namespace App\Modules\WalkIns\Estimation;

use InvalidArgumentException;

/**
 * config('walkins.estimation') as a checked value. Read at boot by the WalkIns provider, so a bad value
 * (an env typo) stops the application instead of quietly producing nonsense waiting times.
 */
final class EstimationConfig
{
    public function __construct(
        public readonly int $windowSize,
        public readonly float $trimFraction,
        public readonly int $minSamples,
        public readonly int $maxAgeDays,
        public readonly int $defaultMinutes,
        public readonly int $spreadPercent,
        public readonly int $minRangeWidthMinutes,
        public readonly int $maxLatenessMinutes,
    ) {
        $problems = [];
        $windowSize >= 1 && $windowSize <= 500 || $problems[] = 'window_size must be 1 to 500';
        $trimFraction >= 0.0 && $trimFraction < 0.5 || $problems[] = 'trim_fraction must be at least 0 and below 0.5';
        $minSamples >= 1 && $minSamples <= $windowSize || $problems[] = 'min_samples must be at least 1 and at most window_size';
        $maxAgeDays >= 1 && $maxAgeDays <= 3650 || $problems[] = 'max_age_days must be 1 to 3650';
        $defaultMinutes >= 1 && $defaultMinutes <= 1440 || $problems[] = 'default_minutes must be 1 to 1440';
        $spreadPercent >= 1 && $spreadPercent <= 90 || $problems[] = 'range_spread_percent must be 1 to 90';
        $minRangeWidthMinutes >= 1 && $minRangeWidthMinutes <= 120 || $problems[] = 'min_range_width_minutes must be 1 to 120';
        $maxLatenessMinutes >= $minRangeWidthMinutes && $maxLatenessMinutes <= 480 || $problems[] = 'max_lateness_minutes must be at least min_range_width_minutes and at most 480';
        if ($problems !== []) {
            throw new InvalidArgumentException('Invalid walkins.estimation configuration: '.implode('; ', $problems).'.');
        }
    }

    public static function fromConfig(): self
    {
        $c = config('walkins.estimation');
        if (! is_array($c)) {
            throw new InvalidArgumentException('Missing walkins.estimation configuration.');
        }
        $get = fn (string $key) => $c[$key] ?? throw new InvalidArgumentException("Missing walkins.estimation.{$key}.");

        return new self(
            (int) $get('window_size'), (float) $get('trim_fraction'), (int) $get('min_samples'), (int) $get('max_age_days'),
            (int) $get('default_minutes'), (int) $get('range_spread_percent'), (int) $get('min_range_width_minutes'), (int) $get('max_lateness_minutes'),
        );
    }
}
