<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Users\Models\User;
use App\Shared\Logging\RedactSensitiveData;
use App\Shared\Services\Fingerprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Writes inside the caller's transaction on purpose: if the change rolls back,
 * so does its audit row, and a committed change always has its audit row.
 */
final class AuditLogger implements RecordsAudit
{
    public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $changes = [], bool $accessEvent = false, ?string $clinicId = null): void
    {
        $actor = Auth::user();
        $request = request();

        DB::table('audit_logs')->insert([
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_roles' => $actor instanceof User ? mb_substr($actor->getRoleNames()->implode(','), 0, 255) : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'clinic_id' => $clinicId,
            'changes' => $changes === [] ? null : json_encode(RedactSensitiveData::scrubArray($changes)),
            'is_access_event' => $accessEvent,
            'ip_hash' => Fingerprint::ip($request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'request_id' => Context::get('request_id'),
            'occurred_at' => now(),
        ]);
    }
}
