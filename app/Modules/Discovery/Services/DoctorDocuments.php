<?php

namespace App\Modules\Discovery\Services;

use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Doctors\Models\DoctorSpecialty;
use Illuminate\Support\Facades\DB;

/**
 * Builds a doctor's search documents from PostgreSQL (architecture.md §12.2):
 * one per active practice location, one for online services, or a single
 * profile document when the doctor has neither. Only ACTIVE doctors get any
 * documents. Only public data: never licence numbers, documents or contacts.
 */
final class DoctorDocuments
{
    /** @return list<array<string, mixed>> */
    public function forDoctor(string $doctorId): array
    {
        /** @var DoctorProfile|null $doctor */
        $doctor = DoctorProfile::with(['specialties.specialty', 'specialties.subspecialty', 'activeAssignments.location.clinic'])
            ->where('onboarding_status', 'ACTIVE')->find($doctorId);
        if ($doctor === null) {
            return [];
        }

        $confirmed = $doctor->specialties->filter(fn (DoctorSpecialty $s) => $s->isConfirmed());
        $services = DoctorService::where('doctor_profile_id', $doctor->id)->where('status', 'ACTIVE')->get();
        $base = [
            'doctor_id' => $doctor->id,
            'display_name' => $doctor->display_name,
            'professional_title' => $doctor->professional_title,
            'specialties' => $confirmed->map(fn (DoctorSpecialty $s) => $s->specialty->name)->unique()->values()->all(),
            'specialty_ids' => $confirmed->pluck('specialty_id')->unique()->values()->all(),
            'subspecialties' => $confirmed->map(fn (DoctorSpecialty $s) => $s->subspecialty?->name)->filter()->unique()->values()->all(),
            'subspecialty_ids' => $confirmed->pluck('subspecialty_id')->filter()->unique()->values()->all(),
            'languages' => DB::table('doctor_languages')->where('doctor_profile_id', $doctor->id)->orderBy('language_code')->pluck('language_code')->all(),
            'gender' => $doctor->gender,
            'years_experience' => $doctor->years_experience,
            'is_verified' => $doctor->license_verification_status === 'VERIFIED',
            'profile_completeness' => $doctor->profile_completeness,
            'rating' => null,
            'review_count' => 0,
            'rank_score' => RankScore::of($doctor->profile_completeness, null, 0),
            'updated_at' => now()->getTimestamp(),
        ];

        $documents = [];
        $locations = $doctor->activeAssignments->filter(fn (DoctorClinicAssignment $a) => $a->location->status === 'ACTIVE' && $a->location->clinic?->status === 'ACTIVE');
        foreach ($locations as $a) {
            $here = $services->where('clinic_location_id', $a->clinic_location_id);
            $l = $a->location;
            $documents[] = self::clean([
                'id' => $doctor->id.':'.$l->id,
                ...$base,
                'services' => $here->pluck('name')->unique()->values()->all(),
                'clinic_id' => $l->clinic_id,
                'clinic_location_id' => $l->id,
                'clinic_name' => (string) $l->clinic?->name,
                'location_name' => $l->name,
                'hospital_name' => $l->hospital_name,
                'city' => $l->city,
                'province' => $l->province,
                'location' => $l->latitude !== null && $l->longitude !== null ? [(float) $l->latitude, (float) $l->longitude] : null,
                'consultation_modes' => ['IN_PERSON'],
                'fee_min' => $here->min('fee_amount'),
                'fee_max' => $here->max('fee_amount'),
            ]);
        }

        $online = $services->whereNull('clinic_location_id');
        if ($online->isNotEmpty()) {
            $documents[] = self::clean([
                'id' => $doctor->id.':online',
                ...$base,
                'services' => $online->pluck('name')->unique()->values()->all(),
                'consultation_modes' => ['ONLINE'],
                'fee_min' => $online->min('fee_amount'),
                'fee_max' => $online->max('fee_amount'),
            ]);
        }

        if ($documents === []) {
            $documents[] = self::clean(['id' => $doctor->id.':profile', ...$base, 'consultation_modes' => []]);
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed> without nulls (optional fields are simply absent)
     */
    private static function clean(array $doc): array
    {
        return array_filter($doc, fn ($v) => $v !== null);
    }
}
