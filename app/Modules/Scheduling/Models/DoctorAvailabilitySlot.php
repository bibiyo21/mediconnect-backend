<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Precomputed concrete slot (projection, architecture.md §8.2). Rebuildable and advisory: booking always revalidates against the constraints on holds and appointments.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property string $doctor_service_mode_id
 * @property string $slot_kind
 * @property string $status
 * @property int $generation
 */
class DoctorAvailabilitySlot extends Model
{
    use HasUuids;

    public const CREATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'local_date' => 'immutable_date',
            'generation' => 'integer',
        ];
    }
}
