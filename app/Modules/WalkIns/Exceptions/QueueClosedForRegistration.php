<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The queue admits nobody now (409 QUEUE_CLOSED) with reason_code `paused`, `closed`, `cutoff_passed`, `clinic_closed_today` (decision 180) or `not_today` (a queue of another day cannot take a call or a return). */
final class QueueClosedForRegistration extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'paused' => 'The clinic has paused this queue for now.',
            'cutoff_passed' => 'This queue is no longer taking new walk-ins today.',
            'clinic_closed_today' => 'The clinic is closed today.',
            'not_today' => 'This queue belongs to another day.',
            default => 'This queue is closed.',
        });
    }

    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QueueClosed;
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }
}
