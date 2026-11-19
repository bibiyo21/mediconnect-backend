<?php

namespace App\Modules\Clinics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $clinic_location_id
 * @property int $day_of_week 1 = Monday … 7 = Sunday
 * @property string $opens_at
 * @property string $closes_at
 */
class ClinicOperatingHour extends Model
{
    use HasUuids;

    protected $fillable = ['day_of_week', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }
}
