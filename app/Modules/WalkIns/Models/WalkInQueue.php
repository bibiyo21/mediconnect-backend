<?php

namespace App\Modules\WalkIns\Models;

use App\Modules\WalkIns\Enums\QueueStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day's walk-in queue for a doctor (or service pool) at a location (database-schema.md §8).
 * Registrations lock this row FOR UPDATE before incrementing last_number.
 *
 * @property string $id
 * @property string $clinic_location_id
 * @property string|null $doctor_profile_id
 * @property string|null $clinic_service_id
 * @property CarbonImmutable $local_date
 * @property QueueStatus $status
 * @property int|null $capacity
 * @property CarbonImmutable|null $cutoff_at
 * @property int $last_number
 * @property string|null $number_prefix
 * @property bool $allow_pre_checkin_registration reserved and unused: remote registration before check-in is refused (B2)
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 */
class WalkInQueue extends Model
{
    use HasUuids;

    protected $table = 'walkin_queues';

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'OPEN', 'last_number' => 0, 'allow_pre_checkin_registration' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'status' => QueueStatus::class,
            'capacity' => 'integer',
            'cutoff_at' => 'immutable_datetime',
            'last_number' => 'integer',
            'allow_pre_checkin_registration' => 'boolean',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<WalkInQueueEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalkInQueueEntry::class, 'walkin_queue_id');
    }
}
