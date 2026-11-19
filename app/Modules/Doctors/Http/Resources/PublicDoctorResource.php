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
 * account or verification history (§5.2). `languages` comes from the caller's
 * prefetch so a list does not query once per doctor.
 *
 * @mixin DoctorProfile
 */
final class PublicDoctorResource extends JsonResource
{
    /** @param list<string> $languages */
    public function __construct(DoctorProfile $doctor, private readonly array $languages, private readonly bool $detail = false)
    {
        parent::__construct($doctor);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorProfile $p */
        $p = $this->resource;
        $specialties = $p->specialties->filter(fn (DoctorSpecialty $s) => $s->isConfirmed())->values();
        $primary = $specialties->first(fn (DoctorSpecialty $s) => $s->is_primary);

        $data = [
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
            /** ISO 639 codes */
            'languages' => $this->languages,
            'years_experience' => $p->years_experience,
            'practice_locations' => $p->activeAssignments->map(fn (DoctorClinicAssignment $a) => [
                'clinic_id' => $a->location->clinic_id,
                'clinic_name' => (string) $a->location->clinic?->name,
                'clinic_location_id' => $a->clinic_location_id,
                'location_name' => $a->location->name,
                'city' => $a->location->city,
            ])->values()->all(),
        ];

        if ($this->detail) {
            $data['biography'] = $p->biography;
            $data['medical_school'] = $p->medical_school;
            $data['gender'] = $p->gender;
            $data['education'] = $p->education->map(fn ($e) => ['institution' => $e->institution, 'degree' => $e->degree, 'year_completed' => $e->year_completed])->values()->all();
        }

        return $data;
    }
}
