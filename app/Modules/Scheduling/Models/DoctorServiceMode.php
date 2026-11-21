<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * APPOINTMENT, WALK_IN or BOTH for one doctor, location (or online) and service (§7.1). Never assumed equal across locations.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property string $doctor_service_id
 * @property string $consultation_type
 * @property string $service_mode
 * @property string $status
 */
class DoctorServiceMode extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
        ];
    }
}
