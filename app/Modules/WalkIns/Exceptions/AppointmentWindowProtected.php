<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The call would overlap a confirmed appointment or an active hold (409 APPOINTMENT_WINDOW_PROTECTED), with reason_code `appointment` or `hold`. */
final class AppointmentWindowProtected extends DomainException
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
        return ErrorCode::AppointmentWindowProtected;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
