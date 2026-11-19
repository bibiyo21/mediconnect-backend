<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorEducation;
use App\Modules\Doctors\Models\DoctorExperience;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorSpecialty;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * The doctor's own profile and onboarding progress. The licence number is
 * masked even here; rejection reasons are shown so the doctor can fix them.
 *
 * @mixin DoctorProfile
 */
final class MyDoctorProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorProfile $p */
        $p = $this->resource;
        $licence = $p->prc_license_number_encrypted;

        return [
            'id' => $p->id,
            'display_name' => $p->display_name,
            'professional_title' => $p->professional_title,
            'biography' => $p->biography,
            'prc_license_number_masked' => $licence !== null ? '•••'.substr($licence, -3) : null,
            'license_verification_status' => $p->license_verification_status,
            'onboarding_status' => $p->onboarding_status->value,
            'licence_locked' => $p->onboarding_status->licenceLocked(),
            'medical_school' => $p->medical_school,
            'years_experience' => $p->years_experience,
            'gender' => $p->gender,
            /** @var list<string> ISO 639 codes */
            'languages' => $p->languageCodes(),
            'specialties' => $p->specialties->map(fn (DoctorSpecialty $s) => [
                'specialty_id' => $s->specialty_id, 'specialty_name' => $s->specialty->name,
                'subspecialty_id' => $s->subspecialty_id, 'subspecialty_name' => $s->subspecialty?->name,
                'is_primary' => $s->is_primary, 'board_certified' => $s->board_certified,
            ])->values()->all(),
            'education' => $p->education->map(fn (DoctorEducation $e) => ['institution' => $e->institution, 'degree' => $e->degree, 'year_completed' => $e->year_completed])->values()->all(),
            'experience' => $p->experience->map(fn (DoctorExperience $e) => ['organization' => $e->organization, 'position' => $e->position, 'started_on' => $e->started_on?->toDateString(), 'ended_on' => $e->ended_on?->toDateString()])->values()->all(),
            'profile_completeness' => $p->profile_completeness,
            'verification_history' => DB::table('doctor_verification_events')->where('doctor_profile_id', $p->id)->orderBy('id')->get(['from_status', 'to_status', 'reason', 'occurred_at'])
                ->map(fn (object $e) => ['from_status' => $e->from_status, 'to_status' => (string) $e->to_status, 'reason' => $e->reason, 'occurred_at' => CarbonImmutable::parse((string) $e->occurred_at)->toIso8601ZuluString()])->values()->all(),
        ];
    }
}
