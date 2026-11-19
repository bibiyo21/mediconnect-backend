<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorSpecialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor as the public sees them (increment 2.11). ACTIVE doctors only; only
 * specialties a reviewer confirmed (D-40). Never the licence number, documents,
 * account or verification history (§5.2). Languages come from the
 * controller's prefetch (`language_codes` attribute), so a list does not
 * query once per doctor.
 *
 * @mixin DoctorProfile
 */
class PublicDoctorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorProfile $p */
        $p = $this->resource;
        $specialties = $p->specialties->filter(fn (DoctorSpecialty $s) => $s->isConfirmed())->values();
        $primary = $specialties->first(fn (DoctorSpecialty $s) => $s->is_primary);

        return [
            'id' => $p->id,
            'display_name' => $p->display_name,
            'professional_title' => $p->professional_title,
            /** The PRC licence was checked by a MediConnect reviewer. */
            'licence_verified' => $p->license_verification_status === 'VERIFIED',
            'primary_specialty' => $primary?->specialty->name,
            'specialties' => $specialties->map(fn (DoctorSpecialty $s) => [
                'specialty_id' => $s->specialty_id,
                'name' => $s->specialty->name,
                'subspecialty' => $s->subspecialty?->name,
                'board_certified' => $s->board_certified,
            ])->all(),
            /** @var list<string> ISO 639 codes */
            'languages' => array_values((array) ($p->getAttribute('language_codes') ?? [])),
            'years_experience' => $p->years_experience,
            'practice_locations' => $p->activeAssignments->map(fn (DoctorClinicAssignment $a) => [
                'clinic_id' => $a->location->clinic_id,
                'clinic_name' => (string) $a->location->clinic?->name,
                'clinic_location_id' => $a->clinic_location_id,
                'location_name' => $a->location->name,
                'city' => $a->location->city,
            ])->values()->all(),
        ];
    }
}
