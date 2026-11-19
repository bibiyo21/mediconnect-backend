<?php

namespace App\Modules\Doctors\Enums;

/** Kinds of credential document (database-schema.md §2). */
enum CredentialType: string
{
    case PrcId = 'PRC_ID';
    case SpecialtyBoard = 'SPECIALTY_BOARD';
    case Diploma = 'DIPLOMA';
    case Other = 'OTHER';
}
