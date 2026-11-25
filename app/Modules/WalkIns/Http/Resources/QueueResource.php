<?php

namespace App\Modules\WalkIns\Http\Resources;

use App\Modules\WalkIns\Models\WalkInQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A walk-in queue as staff see it after pausing or opening it.
 *
 * @property WalkInQueue $resource
 */
final class QueueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $q = $this->resource;

        return [
            'id' => $q->id,
            'clinic_location_id' => $q->clinic_location_id,
            'doctor_profile_id' => $q->doctor_profile_id,
            'local_date' => $q->local_date->toDateString(),
            /** OPEN, PAUSED or CLOSED. */
            'status' => $q->status->value,
            /** The queue number prefix (for example `A`). */
            'number_prefix' => $q->number_prefix,
            /** @var int|null Null means no cap (only the cutoff stops registrations). */
            'capacity' => $q->capacity,
            /** @var string|null UTC. */
            'cutoff_at' => $q->cutoff_at?->toIso8601ZuluString(),
        ];
    }
}
