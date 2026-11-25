<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** An entry or queue move that is not allowed from the current state: 409 INVALID_STATE_TRANSITION, with the current status so a client can resync. */
final class InvalidEntryTransition extends DomainException
{
    private function __construct(public readonly string $current, string $message)
    {
        parent::__construct($message);
    }

    public static function entry(EntryStatus $from, string $action): self
    {
        return new self($from->value, "A walk-in entry that is {$from->value} cannot be {$action}.");
    }

    public static function queue(QueueStatus $from, string $action): self
    {
        return new self($from->value, "A queue that is {$from->value} cannot be {$action}.");
    }

    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }

    public function reasonCode(): string
    {
        return 'invalid_transition';
    }

    public function errors(): array
    {
        return ['status' => [$this->current]];
    }
}
