<?php

namespace App\Modules\Presence\Http\Controllers;

use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Presence\Http\Resources\PublicPresenceResource;
use App\Modules\Presence\Services\PresenceReader;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The public presence of a doctor (increment 5.3, phase 5 plan Q13): a whitelisted status only.
 *
 * PRODUCTION EXPOSURE NEEDS OWNER SIGN-OFF. A public "is Dr. X at this clinic right now" answer shows a
 * named professional's whereabouts to anyone, unauthenticated. Mitigations: the status set is tiny, no
 * reason, time, actor or device is shown, only active listed doctors, the location and clinic are rechecked
 * at read time, the answer is cached server-side for 10 seconds only (browsers and shared caches must revalidate: Cache-Control private, no-cache) and limited to 60 a
 * minute per IP. The owner has not approved the public channel (D-31); until they do, treat this endpoint as
 * a candidate to disable or restrict to signed-in users in production.
 */
final class PublicPresenceController
{
    public function __construct(private readonly PresenceReader $reader) {}

    /**
     * A doctor's presence right now.
     *
     * Public. Only active, listed doctors are visible (404 otherwise). The status is indicative and
     * set by the doctor or clinic staff; it is not a guarantee that the doctor can see you.
     */
    public function show(string $doctorId): JsonResponse
    {
        $doctor = DoctorProfile::where('onboarding_status', 'ACTIVE')->findOrFail($doctorId);

        return ApiResponse::success(new PublicPresenceResource($this->reader->publicStatus($doctor->id)))
            ->header('Cache-Control', 'private, no-cache');
    }
}
