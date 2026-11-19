<?php

namespace App\Modules\Doctors\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $institution
 * @property string $degree
 * @property int|null $year_completed
 */
class DoctorEducation extends Model
{
    use HasUuids;

    protected $table = 'doctor_education';

    protected $fillable = ['institution', 'degree', 'year_completed'];

    protected function casts(): array
    {
        return ['year_completed' => 'integer'];
    }
}
