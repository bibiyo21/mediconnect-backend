<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\DoctorScheduleException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A schedule exception for the doctor and their clinics. The reason is shown
 * only to the doctor and clinic members who manage the schedule, never publicly.
 *
 * @mixin DoctorScheduleException
 */
final class ScheduleExceptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorScheduleException $e */
        $e = $this->resource;

        return [
            'id' => $e->id,
            'doctor_profile_id' => $e->doctor_profile_id,
            'clinic_location_id' => $e->clinic_location_id,
            'doctor_service_mode_id' => $e->getAttribute('doctor_service_mode_id'),
            'exception_type' => $e->exception_type,
            'effect' => $e->effect,
            /** D-11: 1 is the highest. */
            'priority' => $e->priority,
            'starts_at' => $e->getAttribute('starts_at')?->toIso8601ZuluString(),
            'ends_at' => $e->getAttribute('ends_at')?->toIso8601ZuluString(),
            'reason' => $e->getAttribute('reason'),
            'status' => $e->status,
        ];
    }
}
