<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekday window at a location (or online): local wall-clock times plus the IANA zone, slot length, buffer and caps (D-09).
 *
 * @property string $id
 * @property string $doctor_schedule_id
 * @property int $day_of_week
 * @property string|null $clinic_location_id
 * @property string $doctor_service_mode_id
 * @property string $starts_at_local
 * @property string $ends_at_local
 * @property string $timezone
 * @property int $slot_duration_minutes
 * @property int $buffer_minutes
 * @property int|null $max_appointments
 * @property int|null $walkin_capacity
 */
class DoctorScheduleShift extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'slot_duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'max_appointments' => 'integer',
            'walkin_capacity' => 'integer',
        ];
    }

    /** @return HasMany<DoctorScheduleBreak, $this> */
    public function breaks(): HasMany
    {
        return $this->hasMany(DoctorScheduleBreak::class, 'doctor_schedule_shift_id');
    }
}
