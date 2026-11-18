<?php

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Enums\ConsentType;

interface RecordsConsents
{
    /** Appends a consent row and returns its id (for records that cite the consent). */
    public function record(string $userId, ConsentType $type, string $documentVersion, bool $granted, ?string $subjectPatientProfileId = null): int;
}
