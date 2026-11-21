<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Booking rules resolved field by field, most specific first: service mode, doctor, clinic location, platform (architecture.md §8.1). A null value inherits.
 *
 * @property string $id
 * @property string $scope_type
 * @property string|null $scope_id
 */
class SchedulingPolicy extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allow_same_day' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }
}
