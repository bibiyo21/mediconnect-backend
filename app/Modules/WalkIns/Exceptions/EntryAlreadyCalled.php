<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The queue already has a CALLED entry (409 ENTRY_ALREADY_CALLED); the called entry id is returned to staff. */
final class EntryAlreadyCalled extends DomainException
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
        return ErrorCode::EntryAlreadyCalled;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }

    public function errors(): array
    {
        return $this->entryId === null ? [] : ['entry_id' => [$this->entryId]];
    }
}
