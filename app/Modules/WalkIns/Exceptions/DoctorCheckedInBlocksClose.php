<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** A queue cannot close while its doctor is checked in at the location (409 INVALID_STATE_TRANSITION, reason_code doctor_checked_in). */
final class DoctorCheckedInBlocksClose extends DomainException
{
    public function __construct()
    {
        parent::__construct('The doctor is checked in at this location. Check the doctor out before closing the queue.');
    }

    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }

    public function reasonCode(): string
    {
        return 'doctor_checked_in';
    }

    public function errors(): array
    {
        return [];
    }
}
