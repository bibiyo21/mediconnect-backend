<?php

namespace App\Modules\Clinics\Http\Resources;

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicHoliday;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicOperatingHour;
use App\Modules\Clinics\Models\ClinicService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A clinic as the public sees it (increment 2.11): active locations, their
 * public contact details, weekly hours, clinic services and upcoming
 * closures. Never staff, members or the organization's tax details.
 *
 * @mixin Clinic
 */
class PublicClinicResource extends JsonResource
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
            'description' => $c->description,
            'organization_name' => $c->organization->name,
            'locations' => $c->locations->map(fn (ClinicLocation $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'address_line1' => $l->address_line1,
                'address_line2' => $l->address_line2,
                'barangay' => $l->barangay,
                'city' => $l->city,
                'province' => $l->province,
                'postal_code' => $l->postal_code,
                'latitude' => $l->latitude !== null ? (float) $l->latitude : null,
                'longitude' => $l->longitude !== null ? (float) $l->longitude : null,
                'timezone' => $l->timezone,
                /** The clinic's public business number. */
                'phone' => $l->phone,
                'hospital_name' => $l->hospital_name,
                'operating_hours' => $l->operatingHours->map(fn (ClinicOperatingHour $h) => [
                    'day_of_week' => $h->day_of_week, 'opens_at' => substr($h->opens_at, 0, 5), 'closes_at' => substr($h->closes_at, 0, 5),
                ])->values()->all(),
                'services' => $l->services->where('status', 'ACTIVE')->map(fn (ClinicService $s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function closures(Clinic $c): array
    {
        return $c->holidays->filter(fn (ClinicHoliday $h) => $h->date->greaterThanOrEqualTo(today()))->sortBy('date')->take(10)->map(fn (ClinicHoliday $h) => [
            'clinic_location_id' => $h->clinic_location_id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'is_closed' => $h->is_closed,
            'special_opens_at' => $h->special_opens_at !== null ? substr($h->special_opens_at, 0, 5) : null,
            'special_closes_at' => $h->special_closes_at !== null ? substr($h->special_closes_at, 0, 5) : null,
        ])->values()->all();
    }
}
