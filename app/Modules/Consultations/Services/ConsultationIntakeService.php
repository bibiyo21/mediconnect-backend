<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\Contracts\ConsultationIntake;
use App\Modules\Consultations\DTOs\WalkInCallIntake;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use LogicException;

/**
 * Walk-in call to consultation (5.6 plan D3). One consultation row per entry (consultations.walkin_queue_entry_id is UNIQUE),
 * so a skipped patient who is returned and called again gets the same row back. The caller holds the queue lock, so no two
 * callers race on one entry; the database triggers and the UNIQUE index stay the backstop.
 */
final class ConsultationIntakeService implements ConsultationIntake
{
    public function __construct(private readonly CareRelationships $care) {}

    public function openForWalkIn(WalkInCallIntake $call): string
    {
        $existing = Consultation::query()->where('walkin_queue_entry_id', $call->entryId)->lockForUpdate()->first();
        if ($existing === null) {
            $consultation = new Consultation;
            $consultation->forceFill([
                'walkin_queue_entry_id' => $call->entryId,
                'doctor_profile_id' => $call->doctorProfileId,
                'patient_profile_id' => $call->patientProfileId,
                'clinic_location_id' => $call->clinicLocationId,
                'consultation_type' => 'IN_PERSON',
                'status' => ConsultationStatus::Scheduled,
            ])->save();

            return $consultation->id;
        }

        return match ($existing->status) {
            ConsultationStatus::Scheduled, ConsultationStatus::WaitingRoom => $existing->id,
            ConsultationStatus::Cancelled => $this->revive($existing, $call),
            default => throw new LogicException("A walk-in entry with a {$existing->status->value} consultation cannot be called."),
        };
    }

    public function cancelForWalkIn(string $entryId, string $reasonCode): void
    {
        $consultation = Consultation::query()->where('walkin_queue_entry_id', $entryId)->lockForUpdate()->first();
        if ($consultation !== null) {
            // A finished consultation keeps its care relationship (the doctor still treated the patient): nothing to undo.
            if ($consultation->status === ConsultationStatus::Completed) {
                return;
            }
            match ($consultation->status) {
                ConsultationStatus::Scheduled, ConsultationStatus::WaitingRoom => $consultation->forceFill(['status' => ConsultationStatus::Cancelled])->save(),
                ConsultationStatus::InProgress, ConsultationStatus::Interrupted => throw new LogicException("A {$consultation->status->value} consultation cannot be cancelled by the queue."),
                ConsultationStatus::Cancelled => null,
            };
        }
        $this->care->revokeForSource(CareRelationships::SOURCE_WALK_IN, $entryId);
    }

    private function revive(Consultation $consultation, WalkInCallIntake $call): string
    {
        $consultation->forceFill([
            'doctor_profile_id' => $call->doctorProfileId,
            'patient_profile_id' => $call->patientProfileId,
            'clinic_location_id' => $call->clinicLocationId,
            'status' => ConsultationStatus::Scheduled,
            'started_at' => null,
            'ended_at' => null,
            'duration_seconds' => null,
        ])->save();

        return $consultation->id;
    }
}
