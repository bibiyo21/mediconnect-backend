<?php

namespace App\Modules\Discovery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One doctor in search results: a summary, not a full profile (open
 * `/doctors/{id}` for that). Built from public index documents only.
 *
 * @property array<string, mixed> $resource
 */
final class DoctorSearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string */
            'doctor_id' => $r['doctor_id'],
            /** @var string */
            'display_name' => $r['display_name'],
            /** @var string|null */
            'professional_title' => $r['professional_title'],
            /** @var list<string> confirmed specialties only (D-40) */
            'specialties' => $r['specialties'],
            /** @var list<string> */
            'languages' => $r['languages'],
            /** @var int|null */
            'years_experience' => $r['years_experience'],
            /** @var bool PRC licence checked by MediConnect */
            'is_verified' => $r['is_verified'],
            /** @var bool */
            'offers_online' => $r['offers_online'],
            /** @var array{amount: int, currency: string, formatted: string}|null lowest fee among the matching services */
            'fee_from' => $r['fee_from'],
            /** @var list<array{clinic_id: string, clinic_name: string, clinic_location_id: string, location_name: string, city: string, fee_min: int|null, fee_max: int|null, distance_km: float|null}> */
            'locations' => $r['locations'],
        ];
    }
}
