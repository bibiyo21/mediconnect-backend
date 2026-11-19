<?php

namespace App\Modules\Clinics\Http\Resources;

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicHoliday;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Clinics\Models\ClinicOperatingHour;
use App\Modules\Clinics\Models\ClinicService;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything a clinic's managers and staff (or a global admin) work with.
 * `abilities` says which changes the caller may make, so the console can
 * hide what the API would refuse.
 *
 * @mixin Clinic
 */
final class ClinicManagementResource extends JsonResource
{
    /** @param array{update: bool, manage_hours: bool, manage_staff: bool, manage_doctors: bool} $abilities */
    public function __construct(Clinic $clinic, private readonly array $abilities)
    {
        parent::__construct($clinic);
    }

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
            'status' => $c->status,
            'organization' => ['id' => $c->organization->id, 'name' => $c->organization->name, 'legal_name' => $c->organization->legal_name, 'organization_type' => $c->organization->organization_type],
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
                'phone' => $l->phone,
                'hospital_name' => $l->hospital_name,
                'status' => $l->status,
                'operating_hours' => $l->operatingHours->map(fn (ClinicOperatingHour $h) => [
                    'day_of_week' => $h->day_of_week, 'opens_at' => substr($h->opens_at, 0, 5), 'closes_at' => substr($h->closes_at, 0, 5),
                ])->values()->all(),
                'services' => $l->services->map(fn (ClinicService $s) => [
                    'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'default_duration_minutes' => $s->default_duration_minutes, 'status' => $s->status,
                ])->values()->all(),
            ])->values()->all(),
            'holidays' => $c->holidays->map(fn (ClinicHoliday $h) => [
                'id' => $h->id, 'clinic_location_id' => $h->clinic_location_id, 'date' => $h->date->toDateString(), 'name' => $h->name,
                'is_closed' => $h->is_closed, 'special_opens_at' => $h->special_opens_at !== null ? substr($h->special_opens_at, 0, 5) : null,
                'special_closes_at' => $h->special_closes_at !== null ? substr($h->special_closes_at, 0, 5) : null,
            ])->values()->all(),
            'members' => $c->members->where('status', 'ACTIVE')->map(fn (ClinicMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => trim(($m->user->profile->first_name ?? '').' '.($m->user->profile->last_name ?? '')) ?: null,
                'email' => $m->user->email,
                'mobile_masked' => $m->user->mobile_e164 ? PhoneNumber::parse($m->user->mobile_e164)->masked() : null,
                'member_role' => $m->member_role,
                'clinic_location_id' => $m->clinic_location_id,
                'joined_at' => $m->joined_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'abilities' => $this->abilities,
        ];
    }
}
