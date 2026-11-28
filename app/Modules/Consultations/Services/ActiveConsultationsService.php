<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Consultations\DTOs\UnfinishedConsultation;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;

final class ActiveConsultationsService implements ActiveConsultations
{
    public function inProgressFor(string $doctorProfileId): ?string
    {
        $id = Consultation::query()->where('doctor_profile_id', $doctorProfileId)
            ->where('status', ConsultationStatus::InProgress->value)->value('id');

        return is_string($id) ? $id : null;
    }

    public function unfinishedFor(string $doctorProfileId): ?UnfinishedConsultation
    {
        /** @var Consultation|null $row */
        $row = Consultation::query()->where('doctor_profile_id', $doctorProfileId)
            ->whereIn('status', [ConsultationStatus::InProgress->value, ConsultationStatus::Interrupted->value])
            ->orderByRaw("CASE WHEN status = 'IN_PROGRESS' THEN 0 ELSE 1 END")
            ->first(['id', 'status', 'clinic_location_id']);

        if ($row === null) {
            return null;
        }

        $location = $row->clinic_location_id;

        return new UnfinishedConsultation((string) $row->id, $row->status, is_string($location) ? $location : null);
    }
}
