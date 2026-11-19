<?php

namespace App\Modules\Doctors\Enums;

/** Reviewer decision on one document (set in increment 2.9). */
enum CredentialStatus: string
{
    case Submitted = 'SUBMITTED';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
}
