<?php

namespace App\Modules\Presence\Http\Resources;

use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Models\DoctorPresenceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor's own presence, for the doctor and for staff acting for them. Not public: it carries
 * times. The public view is PublicPresenceResource.
 *
 * @property DoctorPresenceSession|null $resource
 */
final class PresenceStateResource extends JsonResource
{
    public function __construct($resource, private readonly ?PresenceStatus $effective = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            /** The stored status: IN, ON_BREAK or TEMPORARILY_UNAVAILABLE while a session is open, otherwise NOT_CHECKED_IN. */
            'status' => ($session->current_status ?? PresenceStatus::NotCheckedIn)->value,
            /** The status a patient would see: BUSY while the stored IN doctor has a consultation in progress (derived, never stored). */
            'effective_status' => ($this->effective ?? $session->current_status ?? PresenceStatus::NotCheckedIn)->value,
            /** @var string|null Null when there is no open session. */
            'session_id' => $session?->id,
            /** @var string|null */
            'clinic_location_id' => $session?->clinic_location_id,
            /** @var string|null UTC. */
            'checked_in_at' => $session?->checked_in_at->toIso8601ZuluString(),
            /** @var string|null UTC; set only while ON_BREAK. An expectation, not a promise. */
            'break_expected_end_at' => $session?->current_break_expected_end_at?->toIso8601ZuluString(),
            /** @var int|null Increases with every change; clients can ignore an older hint. */
            'version' => $session?->version,
        ];
    }
}
