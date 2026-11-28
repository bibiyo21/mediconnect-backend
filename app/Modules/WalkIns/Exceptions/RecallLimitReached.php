<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The called patient was already recalled as often as the policy allows (409 RECALL_LIMIT_REACHED). */
final class RecallLimitReached extends DomainException
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
        return ErrorCode::RecallLimitReached;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
