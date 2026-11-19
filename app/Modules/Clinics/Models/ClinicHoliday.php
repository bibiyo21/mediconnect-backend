<?php

namespace App\Modules\Clinics\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $clinic_id
 * @property string|null $clinic_location_id
 * @property CarbonImmutable $date
 * @property string $name
 * @property bool $is_closed
 * @property string|null $special_opens_at
 * @property string|null $special_closes_at
 */
class ClinicHoliday extends Model
{
    use HasUuids;

    protected $fillable = ['clinic_location_id', 'date', 'name', 'is_closed', 'special_opens_at', 'special_closes_at'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'is_closed' => 'boolean'];
    }
}
