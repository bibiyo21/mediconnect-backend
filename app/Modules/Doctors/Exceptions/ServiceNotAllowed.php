<?php

namespace App\Modules\Doctors\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Services cannot be offered in the profile's current state. */
final class ServiceNotAllowed extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
