<?php

namespace App\Modules\Specialties\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A medical specialty (clinical reference data, D-39).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property list<string> $synonyms
 * @property bool $is_active
 * @property string $review_status
 */
class Specialty extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'synonyms', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'synonyms' => PostgresTextArray::class];
    }

    /** @return HasMany<Subspecialty, $this> */
    public function subspecialties(): HasMany
    {
        return $this->hasMany(Subspecialty::class)->orderBy('name');
    }
}
