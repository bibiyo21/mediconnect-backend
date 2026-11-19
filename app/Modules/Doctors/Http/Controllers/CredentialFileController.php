<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Services\CredentialDocuments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /doctors/credentials/{credentialId}/file — the signed download link
 * issued by MyCredentialsController::downloadUrl. The `signed` middleware
 * checks the signature and expiry; this re-checks ownership and the scan at
 * download time, so a file quarantined or removed after the link was issued
 * is refused. Responses are never cached and never rendered inline.
 */
final class CredentialFileController
{
    public function __invoke(Request $request, string $credentialId, CredentialDocuments $documents, RecordsAudit $audit): Response
    {
        $owner = (string) $request->query('owner');
        $credential = DoctorCredential::whereKey($credentialId)
            ->whereHas('doctorProfile', fn ($q) => $q->where('user_id', $owner))
            ->firstOrFail();
        abort_unless($credential->downloadable(), 404);

        $contents = $documents->read($credential);
        $audit->record('doctor.credential_downloaded', 'doctor_credential', $credential->id, accessEvent: true);

        return response($contents, 200, [
            'Content-Type' => $credential->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'attachment; filename="'.addcslashes(preg_replace('/[^\w.\- ]+/u', '_', $credential->original_filename) ?? 'document', '"\\').'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
