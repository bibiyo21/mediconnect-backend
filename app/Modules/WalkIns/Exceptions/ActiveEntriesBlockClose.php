<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * A queue cannot close while an entry is CALLED or IN_CONSULTATION (409 ACTIVE_CONSULTATION). reason_code `in_progress` when a consultation is
 * running, `called` when a patient is called and not yet started. The entry ids are returned so staff can find them.
 */
final class ActiveEntriesBlockClose extends DomainException
{
    /** @param  list<string>  $entryIds */
    private function __construct(private readonly string $reason, private readonly array $entryIds)
    {
        parent::__construct('Finish or clear the active walk-in entries before closing the queue.');
    }

    /** @param  list<string>  $entryIds */
    public static function inProgress(array $entryIds): self
    {
        return new self('in_progress', $entryIds);
    }

    /** @param  list<string>  $entryIds */
    public static function called(array $entryIds): self
    {
        return new self('called', $entryIds);
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ActiveConsultation;
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }

    public function errors(): array
    {
        return ['entry_id' => $this->entryIds];
    }
}
