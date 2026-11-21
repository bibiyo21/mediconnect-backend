<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A dated override that blocks time or adds special hours; priority per D-11. Never cancels appointments: it flags them (§8.3).
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property string $exception_type
 * @property string $effect
 * @property int $priority
 * @property string $status
 */
class DoctorScheduleException extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
