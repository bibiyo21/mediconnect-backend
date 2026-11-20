<?php

namespace App\Modules\Discovery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One clinic location in search results.
 *
 * @property array<string, mixed> $resource
 */
final class ClinicSearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string */
            'clinic_id' => $r['clinic_id'],
            /** @var string */
            'clinic_location_id' => $r['clinic_location_id'],
            /** @var string */
            'name' => $r['name'],
            /** @var string */
            'location_name' => $r['location_name'],
            /** @var string|null */
            'barangay' => $r['barangay'],
            /** @var string */
            'city' => $r['city'],
            /** @var list<string> */
            'services' => $r['services'],
            /** @var int */
            'doctor_count' => $r['doctor_count'],
            /** @var float|null */
            'distance_km' => $r['distance_km'],
        ];
    }
}
