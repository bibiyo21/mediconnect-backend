<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A break inside a shift (local times).
 *
 * @property string $id
 * @property string $doctor_schedule_shift_id
 * @property string $starts_at_local
 * @property string $ends_at_local
 * @property string|null $label
 */
class DoctorScheduleBreak extends Model
{
    use HasUuids;

    protected $guarded = ['*'];
}
