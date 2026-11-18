<?php

namespace App\Modules\Patients\Enums;

enum GuardianAuthorization: string
{
    /** Reserved for a future verification step; links are authorized on attestation today. */
    case Pending = 'PENDING';
    case Authorized = 'AUTHORIZED';
    case Revoked = 'REVOKED';
}
