<?php

namespace App\Modules\WalkIns\Estimation;

use Carbon\CarbonImmutable;

/**
 * An estimate, never a promise (CLAUDE.md §9.4): always a range, always flagged is_estimate.
 * minMinutes and maxMinutes are null when the wait cannot be estimated (basis contains Unknown).
 */
final class WaitEstimate
{
    public const IS_ESTIMATE = true;

    /**
     * @param  list<WaitBasis>  $basis
     * @param  bool|null  $mayNotBeServedToday  true when the range reaches closing time or tomorrow; null when that cannot be told
     */
    public function __construct(
        public readonly string $entryId,
        public readonly int $position,
        public readonly ?int $minMinutes,
        public readonly ?int $maxMinutes,
        public readonly ?bool $mayNotBeServedToday,
        public readonly array $basis,
        public readonly ?CarbonImmutable $callAtMin,
        public readonly ?CarbonImmutable $callAtMax,
    ) {}

    /** @return array{min_minutes: int|null, max_minutes: int|null, is_estimate: true, may_not_be_served_today: bool|null, basis: list<string>} */
    public function toArray(): array
    {
        return [
            'min_minutes' => $this->minMinutes,
            'max_minutes' => $this->maxMinutes,
            'is_estimate' => self::IS_ESTIMATE,
            'may_not_be_served_today' => $this->mayNotBeServedToday,
            'basis' => array_map(fn (WaitBasis $b) => $b->value, $this->basis),
        ];
    }
}
