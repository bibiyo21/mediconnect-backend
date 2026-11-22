<?php

namespace App\Modules\Presence\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Another change to the same doctor's presence is being processed right now: 409, safe to retry. */
final class PresenceChangeInProgress extends DomainException
{
    public function __construct()
    {
        parent::__construct('Another change to this doctor\'s presence is in progress. Please try again.');
    }

    /** A normal client-side refusal (a double tap, a stale screen): not an error worth a Sentry event. */
    public function report(): bool
    {
        return false;
    }

    public function reasonCode(): string
    {
        return 'change_in_progress';
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ConcurrentModification;
    }
}
