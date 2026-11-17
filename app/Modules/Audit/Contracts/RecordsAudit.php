<?php

namespace App\Modules\Audit\Contracts;

/**
 * Who did what to which record (audit_logs). Changes and reads of sensitive
 * records are both recorded; reads set $accessEvent (medical records, §26.2).
 */
interface RecordsAudit
{
    /**
     * @param  array<string, mixed>  $changes  before/after values; scrubbed of secrets on write
     */
    public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $changes = [], bool $accessEvent = false, ?string $clinicId = null): void;
}
