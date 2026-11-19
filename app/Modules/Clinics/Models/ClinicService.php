<?php

namespace App\Modules\Clinics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A service the clinic offers independent of a doctor (G-02).
 *
 * @property string $id
 * @property string $clinic_location_id
 * @property string $name
 * @property string|null $description
 * @property int|null $default_duration_minutes
 * @property string $status
 */
class ClinicService extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'default_duration_minutes', 'status'];

    protected function casts(): array
    {
        return ['default_duration_minutes' => 'integer'];
    }
}
