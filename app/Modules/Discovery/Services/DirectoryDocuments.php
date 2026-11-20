<?php

namespace App\Modules\Discovery\Services;

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicService;
use App\Modules\Specialties\Models\Specialty;
use App\Modules\Specialties\Models\Subspecialty;
use Illuminate\Support\Facades\DB;

/** Builds clinic and specialty search documents from PostgreSQL (§12.2). */
final class DirectoryDocuments
{
    /**
     * One document per ACTIVE location of an ACTIVE clinic.
     *
     * @return list<array<string, mixed>>
     */
    public function forClinic(string $clinicId): array
    {
        /** @var Clinic|null $clinic */
        $clinic = Clinic::with(['organization', 'locations.services'])->where('status', 'ACTIVE')->find($clinicId);
        if ($clinic === null) {
            return [];
        }

        return array_values($clinic->locations->where('status', 'ACTIVE')->map(fn (ClinicLocation $l) => array_filter([
            'id' => $l->id,
            'clinic_id' => $clinic->id,
            'name' => $clinic->name,
            'organization_name' => $clinic->organization->name,
            'location_name' => $l->name,
            'barangay' => $l->barangay,
            'city' => $l->city,
            'province' => $l->province,
            'hospital_name' => $l->hospital_name,
            'location' => $l->latitude !== null && $l->longitude !== null ? [(float) $l->latitude, (float) $l->longitude] : null,
            'services' => $l->services->where('status', 'ACTIVE')->map(fn (ClinicService $s) => $s->name)->values()->all(),
            'doctor_count' => (int) DB::table('doctor_clinic_assignments')->join('doctor_profiles', 'doctor_profiles.id', '=', 'doctor_clinic_assignments.doctor_profile_id')
                ->where('clinic_location_id', $l->id)->where('doctor_clinic_assignments.status', 'ACTIVE')->where('doctor_profiles.onboarding_status', 'ACTIVE')->count(),
            'updated_at' => now()->getTimestamp(),
        ], fn ($v) => $v !== null))->all());
    }

    /**
     * The specialty and its subspecialties, if active.
     *
     * @return list<array<string, mixed>>
     */
    public function forSpecialty(string $specialtyId): array
    {
        /** @var Specialty|null $specialty */
        $specialty = Specialty::with('subspecialties')->where('is_active', true)->find($specialtyId);
        if ($specialty === null) {
            return [];
        }
        $counts = DB::table('doctor_specialties')->join('doctor_profiles', 'doctor_profiles.id', '=', 'doctor_specialties.doctor_profile_id')
            ->where('doctor_profiles.onboarding_status', 'ACTIVE')->where('doctor_specialties.verification_status', 'CONFIRMED')
            ->where('doctor_specialties.specialty_id', $specialty->id)
            ->selectRaw('subspecialty_id, count(distinct doctor_profile_id) as n')->groupBy('subspecialty_id')->pluck('n', 'subspecialty_id');

        $docs = [[
            'id' => $specialty->id, 'specialty_id' => $specialty->id, 'name' => $specialty->name,
            'synonyms' => $specialty->synonyms, 'kind' => 'SPECIALTY', 'doctor_count' => (int) $counts->sum(),
        ]];
        foreach ($specialty->subspecialties->where('is_active', true) as $sub) {
            /** @var Subspecialty $sub */
            $docs[] = ['id' => $sub->id, 'specialty_id' => $specialty->id, 'name' => $sub->name, 'synonyms' => [], 'kind' => 'SUBSPECIALTY', 'parent_name' => $specialty->name, 'doctor_count' => (int) ($counts[$sub->id] ?? 0)];
        }

        return $docs;
    }
}
