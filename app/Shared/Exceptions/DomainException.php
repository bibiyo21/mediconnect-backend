<?php

namespace App\Shared\Exceptions;

use App\Shared\Enums\ErrorCode;
use RuntimeException;

/**
 * Base for business-rule failures. Rendered as the §23 error envelope with the
 * exception's code; the message is shown to clients, so it must never contain
 * personal or clinical data.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function errorCode(): ErrorCode;

    /**
     * Field-level details for the `errors` member of the envelope.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return [];
    }

    /** A finer machine-readable reason within the error code (sent as `reason_code`); null when the code says it all. */
    public function reasonCode(): ?string
    {
        return null;
    }

    public function clientMessage(): string
    {
        return $this->getMessage() !== '' ? $this->getMessage() : $this->errorCode()->defaultMessage();
    }
}
