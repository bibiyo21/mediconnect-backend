<?php

namespace App\Modules\Appointments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A temporary hold on a slot (§7.5). Expiry is a status change (ACTIVE → EXPIRED), because a constraint predicate cannot read the clock.
 *
 * @property string $id
 * @property string|null $doctor_availability_slot_id
 * @property string $doctor_profile_id
 * @property string $patient_profile_id
 * @property string $booked_by_user_id
 * @property string|null $appointment_id
 * @property string $status
 */
class AppointmentSlotReservation extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'extended_count' => 'integer',
        ];
    }
}
