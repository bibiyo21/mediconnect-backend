<?php

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Audit\Enums\Severity;

interface RecordsSecurityEvents
{
    /**
     * @param  array<string, scalar|null>  $metadata  non-sensitive context only; scrubbed again on write
     */
    public function record(SecurityEventType $type, ?string $userId = null, array $metadata = [], ?Severity $severity = null): void;
}
