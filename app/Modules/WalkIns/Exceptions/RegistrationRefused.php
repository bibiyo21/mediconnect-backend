<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * Registration is not available (409 REGISTRATION_NOT_ALLOWED) with a machine-readable `reason_code`:
 * remote_registration_off, mobile_not_verified, doctor_unavailable, doctor_checked_out, no_walkin_hours_today (decision 137).
 * The message is generic and never names the patient.
 */
final class RegistrationRefused extends DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** A normal client-side refusal: not an error worth a Sentry event. */
    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RegistrationNotAllowed;
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }
}
