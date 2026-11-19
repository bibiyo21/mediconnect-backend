<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DoctorService */
final class DoctorServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorService $s */
        $s = $this->resource;
        $fee = $s->fee();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'consultation_type' => $s->consultation_type->value,
            'clinic_location_id' => $s->clinic_location_id,
            'location_name' => $s->location?->name,
            'clinic_service_id' => $s->clinic_service_id,
            'is_follow_up' => $s->is_follow_up,
            'duration_minutes' => $s->duration_minutes,
            'buffer_minutes' => $s->buffer_minutes,
            /** Fee in centavos, its currency and a display string such as "₱650.00". */
            'fee' => ['amount' => $fee->amount, 'currency' => $fee->currency, 'formatted' => $fee->format()],
            /** Whether the doctor accepts HMOs for this service; coverage is never assumed (§12.10). */
            'is_hmo_eligible' => $s->is_hmo_eligible,
            'status' => $s->status,
            'effective_from' => $s->effective_from->toIso8601ZuluString(),
            'effective_until' => $s->effective_until?->toIso8601ZuluString(),
        ];
    }
}
