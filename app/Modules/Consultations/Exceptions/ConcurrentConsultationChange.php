<?php

namespace App\Modules\Consultations\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** A competing change held a lock past the wait (SQLSTATE 55P03): 409 CONCURRENT_MODIFICATION, retry with a NEW Idempotency-Key. */
final class ConcurrentConsultationChange extends DomainException
{
    public function __construct()
    {
        parent::__construct('Another change to this consultation is being processed. Please try again.');
    }

    public function report(): bool
    {
        return false;
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
