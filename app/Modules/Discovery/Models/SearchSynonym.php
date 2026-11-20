<?php

namespace App\Modules\Discovery\Models;

use App\Modules\Specialties\Models\PostgresTextArray;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A synonym group pushed to the Typesense synonym set (§12.6). Two-way when
 * `root_term` is null; one-way otherwise (searching the root also finds the
 * synonyms, not the reverse).
 *
 * @property string $id
 * @property string|null $root_term
 * @property list<string> $synonyms
 * @property string $status
 * @property string|null $created_by
 * @property CarbonImmutable $updated_at
 */
class SearchSynonym extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return ['synonyms' => PostgresTextArray::class, 'updated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
