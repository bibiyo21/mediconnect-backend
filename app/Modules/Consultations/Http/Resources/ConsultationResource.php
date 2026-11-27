<?php

namespace App\Modules\Consultations\Http\Resources;

use App\Modules\Consultations\Enums\ConsultationView;
use App\Modules\Consultations\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One consultation, in one of two views (rbac-permissions §8). The restricted view is a whitelist (ids, status, place and times) that a
 * contract test holds to exactly these keys. The full view adds the patient profile id and the participant roles. Neither has a name.
 *
 * @mixin Consultation
 */
final class ConsultationResource extends JsonResource
{
    public function __construct($resource, private readonly ConsultationView $view = ConsultationView::Full)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Consultation $consultation */
        $consultation = $this->resource;

        $restricted = [
            'id' => $consultation->id,
            'status' => $consultation->status->value,
            'consultation_type' => $consultation->consultation_type,
            'clinic_location_id' => $consultation->clinic_location_id,
            'doctor_profile_id' => $consultation->doctor_profile_id,
            'started_at' => $consultation->started_at?->toIso8601ZuluString(),
            'ended_at' => $consultation->ended_at?->toIso8601ZuluString(),
            'duration_seconds' => $consultation->duration_seconds,
            'walkin_queue_entry_id' => $consultation->walkin_queue_entry_id,
            'appointment_id' => $consultation->appointment_id,
        ];
        if ($this->view === ConsultationView::Restricted) {
            return $restricted;
        }

        return [
            ...$restricted,
            'patient_profile_id' => $consultation->patient_profile_id,
            /** The roles of the people in the consultation, never their names. Loaded by the caller; empty when not loaded. */
            'participant_roles' => $consultation->relationLoaded('participants')
                ? $consultation->participants->pluck('role')->sort()->values()->all()
                : [],
        ];
    }
}
