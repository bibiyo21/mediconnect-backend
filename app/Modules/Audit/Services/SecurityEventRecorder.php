<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Audit\Enums\Severity;
use App\Shared\Logging\RedactSensitiveData;
use App\Shared\Services\Fingerprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class SecurityEventRecorder implements RecordsSecurityEvents
{
    public function record(SecurityEventType $type, ?string $userId = null, array $metadata = [], ?Severity $severity = null): void
    {
        DB::table('security_events')->insert([
            'user_id' => $userId,
            'event_type' => $type->value,
            'severity' => ($severity ?? $type->defaultSeverity())->value,
            'ip_hash' => Fingerprint::ip(request()->ip()),
            'metadata' => $metadata === [] ? null : json_encode(RedactSensitiveData::scrubArray($metadata)),
            'request_id' => Context::get('request_id'),
            'occurred_at' => now(),
        ]);
    }
}
