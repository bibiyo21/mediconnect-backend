<?php

namespace App\Modules\Discovery\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A normalized query that found nothing, for synonym curation (§12.6).
 * No user identity: text, counts and times only.
 *
 * @property string $id
 * @property string $collection
 * @property string $normalized_query
 * @property int $occurrences
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property string $status
 */
class SearchZeroResult extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurrences' => 'integer', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }
}
