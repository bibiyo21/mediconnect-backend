<?php

namespace App\Modules\Consultations\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * A consultation start or end refused on its state or its doctor's standing. Every code is an existing one (no new ErrorCode):
 * DOCTOR_NOT_AVAILABLE (reason_code checked_out, on_break, unavailable, not_checked_in, no_assignment, doctor_not_active or location_not_active),
 * QUEUE_CLOSED (closed or not_today),
 * INVALID_STATE_TRANSITION (invalid_transition; errors.status is the current status), ACTIVE_CONSULTATION (no reason code).
 */
final class ConsultationRefused extends DomainException
{
    /** @param  array<string, list<string>>  $fields */
    private function __construct(
        private readonly ErrorCode $errorCodeValue,
        string $message,
        private readonly ?string $reason = null,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /** The doctor cannot start a consultation now: presence at this location, no active assignment, or a suspended doctor or location. */
    public static function notAvailable(string $reason): self
    {
        return new self(ErrorCode::DoctorNotAvailable, 'The doctor cannot start a consultation right now.', $reason);
    }

    /** The consultation or the walk-in entry is not in a state that allows the move. */
    public static function invalidTransition(string $currentStatus): self
    {
        return new self(ErrorCode::InvalidStateTransition, 'This consultation cannot be changed from its current status.', 'invalid_transition', ['status' => [$currentStatus]]);
    }

    /** The queue is closed or is not today's (reasons: closed, not_today). */
    public static function queueClosed(string $reason): self
    {
        return new self(ErrorCode::QueueClosed, 'The queue for this consultation is closed.', $reason);
    }

    /** The doctor already has a consultation in progress. */
    public static function activeConsultation(): self
    {
        return new self(ErrorCode::ActiveConsultation, 'The doctor already has a consultation in progress.');
    }

    /** A normal refusal (a stale screen, a double tap): not an error worth a Sentry event. */
    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCodeValue;
    }

    public function reasonCode(): ?string
    {
        return $this->reason;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->fields;
    }
}
