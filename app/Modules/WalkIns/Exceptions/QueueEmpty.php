<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Call-next found no WAITING entry (409 QUEUE_EMPTY). */
final class QueueEmpty extends DomainException
{
    public function __construct(public readonly ?string $reason = null, public readonly ?string $entryId = null)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QueueEmpty;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
