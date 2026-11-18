<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Contracts\RecordsConsents;
use App\Modules\Audit\Enums\ConsentType;
use App\Shared\Services\Fingerprint;
use Illuminate\Support\Facades\DB;

/**
 * Consent is append-only: a withdrawal is a new row with granted = false.
 */
final class ConsentRecorder implements RecordsConsents
{
    public function record(string $userId, ConsentType $type, string $documentVersion, bool $granted, ?string $subjectPatientProfileId = null): int
    {
        return (int) DB::table('consent_records')->insertGetId([
            'user_id' => $userId,
            'subject_patient_profile_id' => $subjectPatientProfileId,
            'consent_type' => $type->value,
            'document_version' => $documentVersion,
            'granted' => $granted,
            'ip_hash' => Fingerprint::ip(request()->ip()),
            'recorded_at' => now(),
        ]);
    }
}
