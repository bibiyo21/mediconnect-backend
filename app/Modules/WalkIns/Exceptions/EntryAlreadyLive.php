<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The patient already has a live entry in this queue (409); the entry id is returned because the caller passed the profile check (decision 144). */
final class EntryAlreadyLive extends DomainException
{
    public function __construct(public readonly string $entryId)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EntryAlreadyLive;
    }

    public function errors(): array
    {
        return ['entry_id' => [$this->entryId]];
    }
}
