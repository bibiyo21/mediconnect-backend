<?php

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Enums\ConsentType;

interface RecordsConsents
{
    public function record(string $userId, ConsentType $type, string $documentVersion, bool $granted, ?string $subjectPatientProfileId = null): void;
}
