<?php

namespace App\Modules\Presence\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * A break or mark-unavailable while the doctor has a consultation in progress (plan 5.7 D-I): 409 ACTIVE_CONSULTATION, no reason code.
 * Stored presence and the running consultation must not disagree, so the change waits until the consultation ends.
 */
final class ActiveConsultationBlocksChange extends DomainException
{
    public function __construct()
    {
        parent::__construct('A consultation is in progress. End it before you take a break or mark yourself unavailable.');
    }

    /** A normal refusal (a stale screen): not an error worth a Sentry event. */
    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ActiveConsultation;
    }
}
