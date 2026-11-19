<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in the verification queue. No licence number, documents or contact
 * details: those are on the detail view, which is audited.
 *
 * @mixin DoctorProfile
 */
final class AdminDoctorSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorProfile $p */
        $p = $this->resource;
        $submitted = $p->getAttribute('submitted_at');

        return [
            'id' => $p->id,
            'display_name' => $p->display_name,
            'professional_title' => $p->professional_title,
            'onboarding_status' => $p->onboarding_status->value,
            /** When the doctor last submitted for review. */
            'submitted_at' => $submitted !== null ? CarbonImmutable::parse((string) $submitted)->toIso8601ZuluString() : null,
            'document_count' => (int) $p->getAttribute('credentials_count'),
            'profile_completeness' => $p->profile_completeness,
        ];
    }
}
