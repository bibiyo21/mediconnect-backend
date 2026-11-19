<?php

namespace App\Modules\Doctors\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization
 * @property string $position
 * @property CarbonImmutable|null $started_on
 * @property CarbonImmutable|null $ended_on
 */
class DoctorExperience extends Model
{
    use HasUuids;

    protected $table = 'doctor_experience';

    protected $fillable = ['organization', 'position', 'started_on', 'ended_on'];

    protected function casts(): array
    {
        return ['started_on' => 'immutable_date', 'ended_on' => 'immutable_date'];
    }
}
