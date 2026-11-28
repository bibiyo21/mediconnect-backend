<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** An explicit call named an entry that is not the head of the queue order (409 NOT_NEXT_IN_ORDER). */
final class NotNextInOrder extends DomainException
{
    public function __construct(public readonly ?string $reason = null, public readonly ?string $entryId = null)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NotNextInOrder;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
