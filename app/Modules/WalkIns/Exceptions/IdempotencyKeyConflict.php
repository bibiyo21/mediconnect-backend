<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The same Idempotency-Key was used earlier for a different registration (the entry-level guard compares the body): 422. */
final class IdempotencyKeyConflict extends DomainException
{
    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::IdempotencyKeyReused;
    }
}
