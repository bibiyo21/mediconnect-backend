<?php

namespace App\Modules\Clinics\Http\Resources;

use App\Modules\Clinics\Models\Clinic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Clinic */
final class ClinicSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Clinic $c */
        $c = $this->resource;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'status' => $c->status,
            'organization' => ['id' => $c->organization->id, 'name' => $c->organization->name, 'organization_type' => $c->organization->organization_type],
            'location_count' => (int) ($c->getAttribute('locations_count') ?? 0),
            'cities' => $c->relationLoaded('locations') ? $c->locations->pluck('city')->unique()->values()->all() : [],
        ];
    }
}
