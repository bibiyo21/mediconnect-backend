<?php

namespace App\Modules\Doctors\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** A credential action is not allowed in the document's or profile's current state. */
final class CredentialNotAvailable extends DomainException
{
    public static function notScanned(): self
    {
        return new self('This document has not passed the malware scan, so it cannot be downloaded.');
    }

    public static function locked(): self
    {
        return new self('Documents cannot be changed while your profile is under review or after it was verified.');
    }

    public static function reviewed(): self
    {
        return new self('An accepted document cannot be removed.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
