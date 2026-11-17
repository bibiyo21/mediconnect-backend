<?php

namespace App\Shared\Exceptions;

use App\Shared\Enums\ErrorCode;

/**
 * A value object or domain rule rejected its input.
 */
final class InvalidValue extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
