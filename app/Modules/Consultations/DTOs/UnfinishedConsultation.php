<?php

namespace App\Modules\Consultations\DTOs;

use App\Modules\Consultations\Enums\ConsultationStatus;

/**
 * A doctor's consultation that still needs an end before check-out (IN_PROGRESS or INTERRUPTED). Ids, status and the consultation's own clinic
 * location (null for an ONLINE consultation). The location decides who may see the id (5.8 M1), not the location of the doctor's session.
 */
final readonly class UnfinishedConsultation
{
    public function __construct(
        public string $id,
        public ConsultationStatus $status,
        public ?string $clinicLocationId,
    ) {}
}
