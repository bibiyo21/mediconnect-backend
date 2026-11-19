<?php

namespace App\Modules\Clinics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant root (D-17).
 *
 * @property string $id
 * @property string $name
 * @property string|null $legal_name
 * @property string $organization_type
 * @property string|null $tin_encrypted
 * @property string $status
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'legal_name', 'organization_type'];

    protected $hidden = ['tin_encrypted'];

    protected function casts(): array
    {
        return ['tin_encrypted' => 'encrypted'];
    }

    /** @return HasMany<Clinic, $this> */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }
}
