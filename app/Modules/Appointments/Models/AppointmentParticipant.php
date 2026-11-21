<?php

namespace App\Modules\Appointments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Who takes part in an appointment, for notifications and video authorization.
 *
 * @property string $id
 * @property string $appointment_id
 * @property string $user_id
 * @property string $participant_role
 */
class AppointmentParticipant extends Model
{
    use HasUuids;

    protected $guarded = ['*'];
}
