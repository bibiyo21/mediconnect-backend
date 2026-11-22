<?php

namespace App\Modules\Presence\Exceptions;

use App\Modules\Presence\Enums\PresenceStatus;
use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * An illegal presence move: 409, with the current status so a retrying client can resync, and a
 * `reason_code` (`open_session`, `not_checked_in`, `invalid_transition`). When the doctor is checked in at
 * another location the status and location are revealed only to those entitled (see checkedInElsewhere).
 */
final class InvalidPresenceTransition extends DomainException
{
    private function __construct(public readonly ?PresenceStatus $current, string $message, private readonly string $reason, private readonly ?string $locationId = null)
    {
        parent::__construct($message);
    }

    public static function between(PresenceStatus $from, PresenceStatus $to): self
    {
        $message = match (true) {
            $from === PresenceStatus::NotCheckedIn => 'You are not checked in at this location.',
            default => "You cannot change from {$from->value} to {$to->value}.",
        };

        return new self($from, $message, $from === PresenceStatus::NotCheckedIn ? 'not_checked_in' : 'invalid_transition');
    }

    /** An open session at the requested location in a state other than IN (a plain repeat of IN is a no-op, not an error). */
    public static function alreadyCheckedIn(PresenceStatus $current): self
    {
        return new self($current, 'This doctor already has an open check-in. Check out first.', 'open_session');
    }

    /** The open session is at another location. $revealLocationId only for the doctor and staff of that location. */
    public static function checkedInElsewhere(?string $revealLocationId): self
    {
        return new self(null, 'This doctor is checked in elsewhere.', 'open_session', $revealLocationId);
    }

    /** A normal client-side refusal (a double tap, a stale screen): not an error worth a Sentry event. */
    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }

    public function errors(): array
    {
        return array_filter([
            'status' => $this->current === null ? null : [$this->current->value],
            'clinic_location_id' => $this->locationId === null ? null : [$this->locationId],
        ]);
    }
}
