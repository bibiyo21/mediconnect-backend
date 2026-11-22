<?php

namespace App\Modules\Presence\Http\Resources;

use App\Modules\Presence\Enums\PublicPresenceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public presence of a doctor (phase 5 plan Q13): a status and a location, nothing more. No
 * reason, time, actor or device. A WebSocket connection or phone signal is not proof of presence.
 *
 * @property array{doctor_id: string, status: PublicPresenceStatus, clinic_location_id: string|null} $resource
 */
final class PublicPresenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'doctor_id' => $this->resource['doctor_id'],
            /** IN, BUSY, ON_BREAK, AWAY or NOT_IN. Indicative: it is set by the doctor or clinic staff and can lag. */
            'status' => $this->resource['status']->value,
            /** @var string|null Where the doctor is checked in; null when NOT_IN. */
            'clinic_location_id' => $this->resource['clinic_location_id'],
        ];
    }
}
