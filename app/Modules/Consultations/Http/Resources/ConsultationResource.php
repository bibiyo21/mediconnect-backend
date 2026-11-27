<?php

namespace App\Modules\Consultations\Http\Resources;

use App\Modules\Consultations\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full view, for the consultation's own doctor (and its patient, later): ids, status and times. No names, no clinical content
 * (none exists in Phase 5). The restricted view for staff and global readers arrives with the reads of increment 5.7-2.
 *
 * @mixin Consultation
 */
final class ConsultationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Consultation $consultation */
        $consultation = $this->resource;

        return [
            'id' => $consultation->id,
            'status' => $consultation->status->value,
            'consultation_type' => $consultation->consultation_type,
            'walkin_queue_entry_id' => $consultation->walkin_queue_entry_id,
            'doctor_profile_id' => $consultation->doctor_profile_id,
            'patient_profile_id' => $consultation->patient_profile_id,
            'clinic_location_id' => $consultation->clinic_location_id,
            'started_at' => $consultation->started_at?->toIso8601ZuluString(),
            'ended_at' => $consultation->ended_at?->toIso8601ZuluString(),
            'duration_seconds' => $consultation->duration_seconds,
        ];
    }
}
