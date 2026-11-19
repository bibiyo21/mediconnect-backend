<?php

namespace App\Modules\Clinics\Exceptions;

use App\Modules\Clinics\Enums\AssignmentStatus;
use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class InvalidAssignmentState extends DomainException
{
    public static function is(AssignmentStatus $status): self
    {
        return new self("This assignment is already {$status->value}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
