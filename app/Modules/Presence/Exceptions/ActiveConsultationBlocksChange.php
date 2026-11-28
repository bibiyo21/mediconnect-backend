<?php

namespace App\Modules\Presence\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * A change that a running or unfinished consultation (or a called walk-in) blocks: 409 ACTIVE_CONSULTATION. Break and unavailable carry no reason
 * code (plan 5.7 D-I). Check-out (increment 5.8, D-C) sends `in_progress`, `interrupted` or `called`. Stored presence and the running consultation
 * must not disagree, so the change waits until the consultation ends.
 */
final class ActiveConsultationBlocksChange extends DomainException
{
    public function __construct(?string $message = null, private readonly ?string $reason = null)
    {
        parent::__construct($message ?? 'A consultation is in progress. End it before you take a break or mark yourself unavailable.');
    }

    public static function inProgress(): self
    {
        return new self('A consultation is in progress. End it before you check out.', 'in_progress');
    }

    public static function interrupted(): self
    {
        return new self('A consultation was interrupted. End it before you check out.', 'interrupted');
    }

    public static function called(): self
    {
        return new self('A patient is called to you. Start, skip or mark them no-show before you check out.', 'called');
    }

    /** HAG-43: a break waits while a walk-in patient is CALLED to the doctor (the same reason code as check-out). */
    public static function calledBeforeBreak(): self
    {
        return new self('A patient is called to you. Start, skip or mark them no-show before you take a break.', 'called');
    }

    /** A normal refusal (a stale screen): not an error worth a Sentry event. */
    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ActiveConsultation;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
