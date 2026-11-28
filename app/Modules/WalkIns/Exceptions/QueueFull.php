<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Patient-app registration over capacity (B9): 409 QUEUE_FULL. No counts are revealed (E2). */
final class QueueFull extends DomainException
{
    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QueueFull;
    }
}
