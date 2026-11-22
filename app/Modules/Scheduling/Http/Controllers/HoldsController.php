<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Scheduling\Http\Requests\CreateHoldRequest;
use App\Modules\Scheduling\Services\SlotHolds;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Temporary holds (increment 4.7, `/availability/holds`). Phase 4 exposes
 * them to the API only; booking on top of a hold arrives in Phase 6 (plan Q5).
 */
final class HoldsController
{
    public function __construct(private readonly SlotHolds $holds) {}

    public function store(CreateHoldRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $own = PatientProfile::where('user_id', $user->id)->value('id');
        abort_if($own === null, 422, 'Complete your patient profile first.');
        $patient = (string) ($request->validated('patient_profile_id') ?? $own);
        if ($patient !== $own) {
            $authorized = Dependent::authorized()->where('guardian_patient_profile_id', $own)->where('dependent_patient_profile_id', $patient)->whereNull('revoked_at')->exists();
            abort_unless($authorized, 403, 'You can reserve times only for yourself and dependants you manage.');
        }

        $hold = $this->holds->hold((string) $request->validated('slot_id'), $patient, $user, $request->header('Idempotency-Key'));

        return ApiResponse::success([
            'reservation_id' => $hold['id'],
            'slot_id' => $hold['slot_id'],
            'starts_at' => $hold['starts_at']->toIso8601ZuluString(),
            'ends_at' => $hold['ends_at']->toIso8601ZuluString(),
            /** The time is kept for you until then; it is released automatically afterwards. */
            'expires_at' => $hold['expires_at']->toIso8601ZuluString(),
        ], status: 201);
    }

    public function destroy(Request $request, string $reservationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->holds->release($reservationId, $user);

        return ApiResponse::success(['reservation_id' => $reservationId, 'status' => 'RELEASED']);
    }
}
