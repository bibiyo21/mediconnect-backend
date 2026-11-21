<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Scheduling\Http\Requests\AvailabilityRequest;
use App\Modules\Scheduling\Services\AvailabilityReader;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Public availability (increment 4.6, api-specification.md). Times are
 * advisory until held: the hold transaction revalidates against PostgreSQL.
 */
final class AvailabilityController
{
    private const NOTE = 'Times can change until you reserve one.';

    public function __construct(private readonly AvailabilityReader $reader) {}

    public function doctor(AvailabilityRequest $request, string $doctorId): JsonResponse
    {
        $doctor = DoctorProfile::where('onboarding_status', 'ACTIVE')->findOrFail($doctorId);

        return ApiResponse::success($this->reader->forDoctor($doctor->id, $request->validated()), meta: ['advisory' => true, 'note' => self::NOTE]);
    }

    /** Several doctors on one date (at most 20), for search result cards. */
    public function many(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'doctor_ids' => ['required', 'array', 'min:1', 'max:20'],
            'doctor_ids.*' => ['uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
        ], ['doctor_ids.max' => 'Ask for at most 20 doctors at a time.'])->validate();
        $active = DoctorProfile::where('onboarding_status', 'ACTIVE')->whereIn('id', $data['doctor_ids'])->pluck('id');
        $out = [];
        foreach ($active as $id) {
            $out[(string) $id] = $this->reader->forDoctor((string) $id, ['from' => $data['date'], 'to' => $data['date']]);
        }

        return ApiResponse::success($out, meta: ['advisory' => true, 'note' => self::NOTE]);
    }
}
