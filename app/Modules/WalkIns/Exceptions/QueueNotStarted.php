<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Remote registration before the doctor has checked in at the location today (B2, decision 179): 409. Not "closed". */
final class QueueNotStarted extends DomainException
{
    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QueueNotStarted;
    }
}
