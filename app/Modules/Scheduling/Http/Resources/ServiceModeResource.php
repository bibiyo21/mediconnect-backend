<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Scheduling\Models\DoctorServiceMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One doctor service with its booking mode. `service_mode` is null until set:
 * such a service shows no availability (architecture.md §8.2).
 *
 * @property array{service: DoctorService, mode: DoctorServiceMode|null} $resource
 */
final class ServiceModeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $service = $this->resource['service'];
        $mode = $this->resource['mode'];

        return [
            'doctor_service_id' => $service->id,
            'doctor_profile_id' => $service->doctor_profile_id,
            'service_name' => $service->name,
            'consultation_type' => $service->consultation_type->value,
            /** @var string|null Null for online services. */
            'clinic_location_id' => $service->clinic_location_id,
            'location_name' => $service->location?->name,
            /** APPOINTMENT, WALK_IN or BOTH; null when not set yet. Online services are APPOINTMENT only. */
            'service_mode' => $mode?->service_mode,
            'service_mode_id' => $mode?->id,
            'effective_from' => $mode?->getAttribute('effective_from')?->toDateString(),
        ];
    }
}
