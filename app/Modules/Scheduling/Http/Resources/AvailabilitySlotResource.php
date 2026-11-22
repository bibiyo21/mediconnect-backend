<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Services\AvailabilityReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bookable time from the availability projection (AvailabilityReader::load).
 * Advisory: a hold revalidates it against PostgreSQL (architecture.md §8).
 *
 * @phpstan-import-type AvailabilitySlot from AvailabilityReader
 *
 * @property AvailabilitySlot $resource
 */
final class AvailabilitySlotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $slot = $this->resource;

        return [
            /** Projection slot id; pass it to POST /holds. */
            'slot_id' => $slot['slot_id'],
            /** UTC, ISO 8601. */
            'starts_at' => $slot['starts_at'],
            /** UTC, ISO 8601. */
            'ends_at' => $slot['ends_at'],
            /** Calendar date in the clinic time zone (YYYY-MM-DD). */
            'local_date' => $slot['local_date'],
            /** IANA time zone used to show this time, e.g. Asia/Manila. */
            'timezone' => $slot['timezone'],
            /** @var string|null Null for online consultations. */
            'clinic_location_id' => $slot['clinic_location_id'],
            'doctor_service_id' => $slot['doctor_service_id'],
            'service_name' => $slot['service_name'],
            /** ONLINE or IN_PERSON. */
            'consultation_type' => $slot['consultation_type'],
        ];
    }
}
