<?php

namespace App\Modules\WalkIns\Http\Resources;

use App\Modules\WalkIns\DTOs\EntryView;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Services\EstimateView;
use App\Modules\WalkIns\Services\QueueLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One walk-in queue entry, rendered for its reader (EntryView decides what the reader may see). A queue number is a place in a
 * queue, not an appointment. The wait is an estimate, never a promise. HMO is a claim by the patient, never a verified coverage.
 *
 * @property EntryView $resource
 */
final class WalkInEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource;
        $e = $v->entry;
        $q = $v->queue;
        $detail = [
            'id' => $e->id,
            /** The number the patient is told, for example `A-07`: a place in this queue, not an appointment. */
            'label' => QueueLabel::of($q->number_prefix, $e->queue_number),
            'queue_number' => $e->queue_number,
            /** REGISTERED (joined from the app, not arrived), WAITING, CALLED, IN_CONSULTATION, COMPLETED, SKIPPED, NO_SHOW, CANCELLED. */
            'status' => $e->status->value,
            'queue_id' => $q->id,
            /** The queue's local date at the location (Y-m-d). */
            'local_date' => $q->local_date->toDateString(),
            'timezone' => $v->timezone,
            'clinic_location_id' => $q->clinic_location_id,
            'location_name' => $v->locationName,
            'doctor_profile_id' => $q->doctor_profile_id,
            'doctor_name' => $v->doctorName,
            'doctor_service_id' => $e->doctor_service_id,
            'service_name' => $v->serviceName,
            /** The listed consultation fee in minor units. Nothing is charged: the fee is paid at the clinic and the final charge is the clinic's to set. */
            'fee' => $v->fee === null ? null : ['amount' => $v->fee->amount, 'currency' => $v->fee->currency],
            'payer_type' => $e->payer_type->value,
            'registered_at' => $e->created_at?->toIso8601ZuluString(),
            'arrived_at' => $e->arrived_at?->toIso8601ZuluString(),
        ];

        if ($v->level === ViewLevel::Owner) {
            return [
                ...$detail,
                /** The estimate, or null once the entry is no longer in the queue. Minutes are withheld when they would be a guess about a guess. */
                'estimate' => $v->estimate === null ? null : EstimateView::forPatient($v->estimate),
            ];
        }
        if ($v->level === ViewLevel::Restricted) {
            return [
                ...$detail,
                /** Restricted readers see place and status only: no name, phone, HMO text or note. */
                'priority_level' => $e->triage_priority->apiName(),
                'registration_channel' => $e->registration_channel->value,
                'is_guest' => $e->isGuest(),
            ];
        }

        /** @var array<string, mixed> $out */
        $out = [
            ...$detail,
            'registration_channel' => $e->registration_channel->value,
            'is_guest' => $e->isGuest(),
            /** The patient's or guest's name; null once a guest's data has been purged. */
            'name' => $v->displayName,
            'priority_level' => $e->triage_priority->apiName(),
            'priority_reason_code' => $e->triage_reason_code?->value,
            /** What the patient says their HMO is called: as typed, not verified. Never a coverage statement. */
            'hmo_claim' => $e->payer_type->value === 'HMO' ? ['label' => 'Patient says HMO: not verified', 'card_name_text' => $e->hmo_card_name_encrypted] : null,
            /** Staff estimate with the basis codes. Minutes appear for the next waiting entries only. */
            'estimate' => $v->estimate === null ? null : EstimateView::forStaff($v->estimate, $v->showMinutes),
        ];
        if ($v->includePhone) {
            // The guest's contact number (+63, E.164): present only for a guest, only in this detail, null after the purge; the read is audited.
            if ($e->isGuest()) {
                $out['guest_phone'] = $e->guest_pii_purged_at === null ? $e->guest_phone_encrypted : null;
            }
            // The short note the staff added to a priority (no symptoms or diagnosis); null after the purge.
            $out['priority_note'] = $e->guest_pii_purged_at === null ? $e->triage_note_encrypted : null;
        }

        return $out;
    }
}
