<?php

namespace App\Shared\ValueObjects;

use App\Shared\Exceptions\InvalidValue;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A half-open time range [start, end) held in UTC — the same semantics as the
 * PostgreSQL tstzrange(..., '[)') used by the scheduling exclusion constraints.
 */
final readonly class TimeRange
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function between(DateTimeInterface $start, DateTimeInterface $end): self
    {
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidValue('A time range must end after it starts.');
        }

        return new self($start, $end);
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end) && $other->start->lessThan($this->end);
    }

    public function contains(DateTimeInterface $instant): bool
    {
        $instant = CarbonImmutable::instance($instant);

        return $instant->greaterThanOrEqualTo($this->start) && $instant->lessThan($this->end);
    }

    public function durationInMinutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }

    public function equals(self $other): bool
    {
        return $this->start->equalTo($other->start) && $this->end->equalTo($other->end);
    }
}
