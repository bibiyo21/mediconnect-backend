<?php

namespace App\Modules\Clinics\Http\Resources;

use App\Modules\Clinics\Models\DoctorClinicAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assignment as the clinic or the doctor sees it. No licence number or
 * contact details: the display name and the place are enough to recognise it.
 *
 * @mixin DoctorClinicAssignment
 */
final class DoctorAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorClinicAssignment $a */
        $a = $this->resource;

        return [
            'id' => $a->id,
            'status' => $a->status->value,
            'doctor_profile_id' => $a->doctor_profile_id,
            'doctor_display_name' => $a->doctorProfile->display_name,
            'clinic_id' => $a->location->clinic_id,
            'clinic_name' => (string) $a->location->clinic?->name,
            'clinic_location_id' => $a->clinic_location_id,
            'location_name' => $a->location->name,
            'location_city' => $a->location->city,
            'effective_from' => $a->effective_from?->toIso8601ZuluString(),
            'effective_until' => $a->effective_until?->toIso8601ZuluString(),
            'end_reason' => $a->end_reason,
            'requested_at' => $a->created_at->toIso8601ZuluString(),
        ];
    }
}
