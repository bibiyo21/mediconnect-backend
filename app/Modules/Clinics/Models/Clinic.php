<?php

namespace App\Modules\Clinics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $status
 * @property-read Organization $organization
 */
class Clinic extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['name', 'description'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ClinicLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(ClinicLocation::class)->orderBy('name');
    }

    /** @return HasMany<ClinicMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ClinicMember::class);
    }

    /** @return HasMany<ClinicHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(ClinicHoliday::class)->orderBy('date');
    }
}
