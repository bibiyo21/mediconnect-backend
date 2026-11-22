<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Scheduling\Http\Requests\AvailabilityRequest;
use App\Modules\Scheduling\Http\Resources\AvailabilitySlotResource;
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

        return ApiResponse::success(AvailabilitySlotResource::collection($this->reader->forDoctor($doctor->id, $request->validated())), meta: ['advisory' => true, 'note' => self::NOTE]);
    }

    /** Several doctors on one date (at most 20), for search result cards. */
    public function many(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'doctor_ids' => ['required', 'array', 'min:1', 'max:20'],
            'doctor_ids.*' => ['uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
        ], ['doctor_ids.max' => 'Ask for at most 20 doctors at a time.'])->validate();
        $active = DoctorProfile::where('onboarding_status', 'ACTIVE')->whereIn('id', $data['doctor_ids'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $active = array_values($active);
        $byDoctor = $this->byDoctor($active, $data['date']);

        // An empty map must encode as {} (the contract says object), not [].
        return ApiResponse::success($byDoctor === [] ? new \stdClass : $byDoctor, meta: ['advisory' => true, 'note' => self::NOTE]);
    }

    /**
     * Times per doctor for one date, keyed by doctor profile id. The slot shape is spelled out
     * (not the AvailabilitySlot alias) because the OpenAPI generator reads this docblock.
     *
     * @param  list<string>  $doctorIds
     * @return array<string, list<array{slot_id: string, starts_at: string, ends_at: string, local_date: string, timezone: string, clinic_location_id: string|null, doctor_service_id: string, service_name: string, consultation_type: string}>>
     */
    private function byDoctor(array $doctorIds, string $date): array
    {
        $out = [];
        foreach ($doctorIds as $id) {
            $out[$id] = $this->reader->forDoctor($id, ['from' => $date, 'to' => $date]);
        }

        return $out;
    }
}
