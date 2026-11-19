<?php

namespace App\Modules\Doctors\Exceptions;

use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class InvalidOnboardingTransition extends DomainException
{
    public static function between(OnboardingStatus $from, OnboardingStatus $to): self
    {
        return new self("A doctor profile cannot move from {$from->value} to {$to->value}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
