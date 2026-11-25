<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** E1, enforced on the server: the range reaches closing time, so the patient must acknowledge before joining (409). */
final class LateRiskAcknowledgementRequired extends DomainException
{
    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::LateRiskAckRequired;
    }
}
