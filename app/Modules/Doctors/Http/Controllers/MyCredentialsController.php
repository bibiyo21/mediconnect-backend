<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Enums\CredentialType;
use App\Modules\Doctors\Exceptions\CredentialNotAvailable;
use App\Modules\Doctors\Http\Requests\UploadCredentialRequest;
use App\Modules\Doctors\Http\Resources\CredentialResource;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Services\CredentialDocuments;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The doctor's own credential documents (increment 2.8). Every lookup is
 * scoped to the caller's profile, so another doctor's document is a 404.
 */
final class MyCredentialsController
{
    public function __construct(private readonly CredentialDocuments $documents, private readonly RecordsAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $credentials = DoctorCredential::where('doctor_profile_id', $this->profile($request)->id)->orderBy('created_at')->get();

        return ApiResponse::success(CredentialResource::collection($credentials));
    }

    public function store(UploadCredentialRequest $request): JsonResponse
    {
        $credential = $this->documents->store(
            $this->profile($request),
            $request->file('file'),
            CredentialType::from((string) $request->input('credential_type')),
            $request->input('expires_on'),
        );

        return ApiResponse::success(new CredentialResource($credential->refresh()), status: 201);
    }

    public function destroy(Request $request, string $credentialId): JsonResponse
    {
        $this->documents->remove($this->own($request, $credentialId));

        return ApiResponse::success(null);
    }

    /**
     * A short-lived signed link to download the file. Only CLEAN documents;
     * the link names the owner and stops working after the configured TTL.
     */
    public function downloadUrl(Request $request, string $credentialId): JsonResponse
    {
        $credential = $this->own($request, $credentialId);
        if (! $credential->downloadable()) {
            throw CredentialNotAvailable::notScanned();
        }

        $expiresAt = now()->addSeconds((int) config('mediconnect.credentials.download_ttl_seconds'))->toImmutable();
        $url = URL::temporarySignedRoute('doctor-credentials.file', $expiresAt, ['credentialId' => $credential->id, 'owner' => $credential->doctorProfile->user_id]);
        $this->audit->record('doctor.credential_link_issued', 'doctor_credential', $credential->id, accessEvent: true);

        return ApiResponse::success(['url' => $url, 'expires_at' => $expiresAt->toIso8601ZuluString()]);
    }

    private function own(Request $request, string $credentialId): DoctorCredential
    {
        return DoctorCredential::where('doctor_profile_id', $this->profile($request)->id)->whereKey($credentialId)->firstOrFail();
    }

    private function profile(Request $request): DoctorProfile
    {
        /** @var User $user */
        $user = $request->user();

        return DoctorProfile::where('user_id', $user->id)->firstOrFail();
    }
}
