<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The doctor cannot take a patient now (409 DOCTOR_NOT_AVAILABLE), with reason_code `on_break`, `unavailable`, `not_checked_in` or `checked_out`. */
final class DoctorNotAvailable extends DomainException
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
        return ErrorCode::DoctorNotAvailable;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
