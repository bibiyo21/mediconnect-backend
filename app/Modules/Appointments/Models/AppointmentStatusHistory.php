<?php

namespace App\Modules\Appointments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of every appointment status change (database trigger forbids updates and deletes).
 *
 * @property int $id
 * @property string $appointment_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $actor_type
 */
class AppointmentStatusHistory extends Model
{
    protected $table = 'appointment_status_history';

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'old_starts_at' => 'immutable_datetime',
            'new_starts_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
