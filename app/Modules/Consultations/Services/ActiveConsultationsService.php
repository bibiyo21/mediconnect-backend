<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Consultations\Contracts\ActiveConsultations;
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
}
