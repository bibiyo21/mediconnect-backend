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
        $w = (array) config('search.ranking.rank_score');
        $prior = (float) ($w['rating_prior'] ?? 3.5);
        $priorWeight = (float) ($w['rating_prior_weight'] ?? 10);
        // Rating damped by review count: a 5.0 from one review counts less than a 4.6 from fifty.
        // No reviews yet: the prior, so new doctors are neither boosted nor buried.
        $damped = $rating === null ? $prior / 5 : ($rating * $reviewCount + $prior * $priorWeight) / ($reviewCount + $priorWeight) / 5;
        $completeness = max(0, min(100, $profileCompleteness)) / 100;

        return round((float) ($w['profile_completeness'] ?? 0.67) * $completeness + (float) ($w['rating'] ?? 0.33) * $damped, 4);
    }
}
