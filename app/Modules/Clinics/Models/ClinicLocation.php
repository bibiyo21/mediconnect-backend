<?php

namespace App\Modules\Clinics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The unit for schedules, queues, presence and accreditation.
 *
 * @property string $id
 * @property string $clinic_id
 * @property string $name
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string|null $barangay
 * @property string $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string $country_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $timezone
 * @property string|null $phone
 * @property string|null $hospital_name
 * @property string $status
 */
class ClinicLocation extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'address_line1', 'address_line2', 'barangay', 'city', 'province', 'postal_code', 'latitude', 'longitude', 'timezone', 'phone', 'hospital_name', 'status'];

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return HasMany<ClinicOperatingHour, $this> */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(ClinicOperatingHour::class)->orderBy('day_of_week')->orderBy('opens_at');
    }

    /** @return HasMany<ClinicService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(ClinicService::class)->orderBy('name');
    }
}
