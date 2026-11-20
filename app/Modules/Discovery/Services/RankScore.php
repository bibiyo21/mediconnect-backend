<?php

namespace App\Modules\Discovery\Services;

/**
 * Precomputed ranking signal (architecture.md §12.4): used after text match,
 * and as the main order when browsing without text. Never takes paid placement
 * as an input. Weights are tuned in increment 3.5; availability joins in Phase 4.
 */
final class RankScore
{
    public static function of(int $profileCompleteness, ?float $rating, int $reviewCount): float
    {
        // Rating damped by review count: a 5.0 from one review counts less than a 4.6 from fifty.
        $damped = $rating === null ? 0.0 : ($rating * $reviewCount + 3.5 * 10) / ($reviewCount + 10) / 5;

        return round(0.67 * ($profileCompleteness / 100) + 0.33 * $damped, 4);
    }
}
