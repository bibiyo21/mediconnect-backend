<?php

namespace App\Modules\Doctors\Enums;

/** Doctor onboarding (CLAUDE.md §5.2). Only ACTIVE doctors can be booked or listed. */
enum OnboardingStatus: string
{
    case Registered = 'REGISTERED';
    case DocumentsSubmitted = 'DOCUMENTS_SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case Rejected = 'REJECTED';
    case Verified = 'VERIFIED';
    case Active = 'ACTIVE';

    /** @return list<self> the states reachable from this one */
    public function next(): array
    {
        return match ($this) {
            self::Registered => [self::DocumentsSubmitted],
            self::DocumentsSubmitted => [self::UnderReview],
            self::UnderReview => [self::Verified, self::Rejected],
            self::Rejected => [self::DocumentsSubmitted],
            self::Verified => [self::Active],
            self::Active => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** While a reviewer looks at it, or after approval, the licence number may not change. */
    public function licenceLocked(): bool
    {
        return in_array($this, [self::UnderReview, self::Verified, self::Active], true);
    }
}
