<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Actions\UpdateOwnPatientProfile;
use App\Modules\Patients\Http\Requests\UpdateMyPatientProfileRequest;
use App\Modules\Patients\Http\Resources\MyPatientProfileResource;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** GET /patients/me, PATCH /patients/me: the signed-in user's own patient profile. */
final class MyPatientProfileController
{
    public function show(Request $request): JsonResponse
    {
        $profile = $this->ownProfile($request);
        Gate::authorize('view', $profile);

        return ApiResponse::success(new MyPatientProfileResource($profile->load('user.profile', 'user.addresses')));
    }

    public function update(UpdateMyPatientProfileRequest $request, UpdateOwnPatientProfile $update): JsonResponse
    {
        $profile = $this->ownProfile($request);
        Gate::authorize('update', $profile);

        /** @var User $user */
        $user = $request->user();
        $profile = $update->handle($user, $profile, $request->changes());

        return ApiResponse::success(new MyPatientProfileResource($profile->load('user.profile', 'user.addresses')));
    }

    /** A user without a patient profile (for example a staff-only account) gets 404. */
    private function ownProfile(Request $request): PatientProfile
    {
        /** @var User $user */
        $user = $request->user();

        return PatientProfile::where('user_id', $user->id)->firstOrFail();
    }
}
