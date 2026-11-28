<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** A competing registration or queue change held a lock past the wait (SQLSTATE 55P03): 409 CONCURRENT_MODIFICATION, safe to retry. */
final class ConcurrentRegistration extends DomainException
{
    public function __construct()
    {
        parent::__construct('Another change to this queue is being processed. Please try again.');
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ConcurrentModification;
    }

    public function reasonCode(): string
    {
        return 'change_in_progress';
    }
}
