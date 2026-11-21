<?php

namespace App\Modules\Scheduling\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The schedule changed since the client read it (optimistic lock, 409 CONCURRENT_MODIFICATION). */
final class ScheduleConflict extends DomainException
{
    public static function staleVersion(int $current): self
    {
        return new self("This schedule was changed by someone else (now version {$current}). Reload it and try again.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ConcurrentModification;
    }
}
