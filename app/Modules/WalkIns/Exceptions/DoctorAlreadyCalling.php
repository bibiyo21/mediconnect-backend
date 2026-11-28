<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The doctor already has a CALLED entry in another queue (409 DOCTOR_ALREADY_CALLING). */
final class DoctorAlreadyCalling extends DomainException
{
    public function __construct(public readonly ?string $reason = null)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DoctorAlreadyCalling;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }
}
