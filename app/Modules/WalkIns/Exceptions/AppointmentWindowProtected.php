<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The call would overlap a confirmed appointment or an active hold (409 APPOINTMENT_WINDOW_PROTECTED), with reason_code `appointment` or `hold`. */
final class AppointmentWindowProtected extends DomainException
{
    public function __construct(public readonly ?string $reason = null, public readonly ?string $entryId = null, public readonly ?string $blockedUntil = null)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AppointmentWindowProtected;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }

    /** @return array<string, list<string>> when the protection ends (UTC, ISO 8601), so staff can tell the patient how long to wait */
    public function errors(): array
    {
        return $this->blockedUntil === null ? [] : ['blocked_until' => [$this->blockedUntil]];
    }
}
