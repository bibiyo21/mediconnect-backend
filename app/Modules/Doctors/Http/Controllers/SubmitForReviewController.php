<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Modules\Doctors\Enums\CredentialType;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Enums\ScanStatus;
use App\Modules\Doctors\Http\Resources\MyDoctorProfileResource;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Services\DoctorOnboarding;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /doctors/me/submit: send the profile and documents for verification
 * (REGISTERED or REJECTED → DOCUMENTS_SUBMITTED). Needs a licence number and
 * at least one clean PRC ID document; nothing still waiting for its scan.
 */
final class SubmitForReviewController
{
    public function __invoke(Request $request, DoctorOnboarding $onboarding): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = DoctorProfile::where('user_id', $user->id)->firstOrFail();

        $errors = [];
        if ($profile->prc_license_number_hash === null) {
            $errors['prc_license_number'] = ['Add your PRC licence number first.'];
        }
        $documents = DoctorCredential::where('doctor_profile_id', $profile->id)->get();
        if (! $documents->contains(fn (DoctorCredential $c) => $c->credential_type === CredentialType::PrcId && $c->scan_status === ScanStatus::Clean)) {
            $errors['credentials'] = ['Upload your PRC ID. It must finish its security check before you submit.'];
        } elseif ($documents->contains(fn (DoctorCredential $c) => $c->scan_status === ScanStatus::PendingScan)) {
            $errors['credentials'] = ['Wait until every document has finished its security check.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $profile = $onboarding->transition($profile, OnboardingStatus::DocumentsSubmitted, $user->id);

        return ApiResponse::success(new MyDoctorProfileResource($profile->load(['specialties.specialty', 'specialties.subspecialty', 'education', 'experience'])));
    }
}
