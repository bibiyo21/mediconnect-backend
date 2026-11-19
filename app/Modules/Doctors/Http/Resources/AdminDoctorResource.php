<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorSpecialty;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer's view of one application (doctors.verify only, audited as an
 * access event). The full licence number is shown so it can be checked
 * against the PRC register.
 *
 * @mixin DoctorProfile
 */
final class AdminDoctorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorProfile $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'display_name' => $p->display_name,
            'professional_title' => $p->professional_title,
            'biography' => $p->biography,
            'prc_license_number' => $p->prc_license_number_encrypted,
            'license_verification_status' => $p->license_verification_status,
            'onboarding_status' => $p->onboarding_status->value,
            'medical_school' => $p->medical_school,
            'years_experience' => $p->years_experience,
            'profile_completeness' => $p->profile_completeness,
            'specialties' => $p->specialties->map(fn (DoctorSpecialty $s) => [
                'id' => $s->id,
                'specialty_name' => $s->specialty->name,
                'subspecialty_name' => $s->subspecialty?->name,
                'is_primary' => $s->is_primary,
                'board_certified' => $s->board_certified,
                /** @var 'SELF_REPORTED'|'CONFIRMED' */
                'verification_status' => $s->verification_status,
            ])->values()->all(),
            'credentials' => $p->credentials->map(fn (DoctorCredential $c) => (new CredentialResource($c))->toArray($request))->values()->all(),
            'verification_history' => DB::table('doctor_verification_events')->where('doctor_profile_id', $p->id)->orderBy('id')->get(['from_status', 'to_status', 'reason', 'occurred_at'])
                ->map(fn (object $e) => ['from_status' => $e->from_status, 'to_status' => (string) $e->to_status, 'reason' => $e->reason, 'occurred_at' => CarbonImmutable::parse((string) $e->occurred_at)->toIso8601ZuluString()])->values()->all(),
        ];
    }
}
