<?php

namespace App\Modules\Doctors\Jobs;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Doctors\Enums\ScanStatus;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Services\CredentialDocuments;
use App\Shared\Contracts\MalwareScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Scans one credential document (D-07). Fails closed: a scanner error or
 * timeout leaves the file PENDING_SCAN and retries with backoff; after the
 * last attempt it becomes SCAN_FAILED. Neither state is downloadable. An
 * infected file is QUARANTINED and its stored object deleted.
 */
final class ScanCredentialDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly string $credentialId)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(MalwareScanner $scanner, CredentialDocuments $documents, RecordsAudit $audit, RecordsSecurityEvents $security): void
    {
        $credential = DoctorCredential::find($this->credentialId);
        if ($credential === null || $credential->scan_status !== ScanStatus::PendingScan) {
            return; // removed meanwhile, or already decided (idempotent on retry)
        }

        $result = $scanner->scan($documents->read($credential)); // ScannerUnavailable: retry

        $quarantined = DB::transaction(function () use ($credential, $result, $audit) {
            /** @var DoctorCredential|null $locked */
            $locked = DoctorCredential::whereKey($credential->id)->lockForUpdate()->first();
            if ($locked === null || $locked->scan_status !== ScanStatus::PendingScan) {
                return false;
            }
            $locked->forceFill([
                'scan_status' => $result->infected ? ScanStatus::Quarantined : ScanStatus::Clean,
                'scan_signature' => $result->signature,
                'scanned_at' => now(),
            ])->save();
            $audit->record('doctor.credential_scanned', 'doctor_credential', $locked->id, ['scan_status' => $locked->scan_status->value]);

            return $result->infected;
        });

        if ($quarantined) {
            $documents->deleteObject($credential);
            $security->record(SecurityEventType::MalwareDetected, $credential->doctorProfile->user_id, ['credential_id' => $credential->id]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DoctorCredential::whereKey($this->credentialId)
            ->where('scan_status', ScanStatus::PendingScan->value)
            ->update(['scan_status' => ScanStatus::ScanFailed->value, 'scanned_at' => now()]);
    }
}
