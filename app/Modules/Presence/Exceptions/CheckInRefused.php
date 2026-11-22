<?php

namespace App\Modules\Presence\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** A check-in the rules do not allow: no assignment, no service, or the clinic is not open (422). */
final class CheckInRefused extends DomainException
{
    /** @param string $reason machine-readable: doctor_not_active, location_not_active, no_assignment, no_service_mode, clinic_closed, closed_holiday or outside_hours */
    public function __construct(public readonly string $reason, public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    /** A normal client-side refusal (a double tap, a stale screen): not an error worth a Sentry event. */
    public function report(): bool
    {
        return false;
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }

    public function errors(): array
    {
        return [$this->field => [$this->getMessage()]];
    }
}
