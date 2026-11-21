<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An effective-dated weekly pattern of shifts (§7.2). `version` is the optimistic lock (concurrency scenario 4).
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string $name
 * @property string $status
 * @property int $version
 */
class DoctorSchedule extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<DoctorScheduleShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(DoctorScheduleShift::class, 'doctor_schedule_id');
    }
}
