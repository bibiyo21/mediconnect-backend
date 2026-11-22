<?php

namespace App\Modules\WalkIns\Models;

use App\Modules\WalkIns\Enums\EntryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One patient visit in a walk-in queue (database-schema.md §8). A guest (no patient profile)
 * has an encrypted name and optional phone; those never appear in responses, logs or Sentry.
 *
 * @property string $id
 * @property string $walkin_queue_id
 * @property int $queue_number
 * @property string|null $patient_profile_id
 * @property string|null $guest_name_encrypted
 * @property string|null $guest_phone_encrypted
 * @property string|null $guest_phone_hash
 * @property CarbonImmutable|null $guest_pii_purged_at
 * @property string $registered_by_user_id
 * @property string $registration_channel
 * @property EntryStatus $status
 * @property int $triage_priority
 * @property string|null $assigned_doctor_profile_id
 * @property string $payer_type
 * @property string|null $hmo_membership_id
 * @property int $recall_count
 * @property CarbonImmutable|null $estimated_call_at_min
 * @property CarbonImmutable|null $estimated_call_at_max
 * @property CarbonImmutable|null $arrived_at
 * @property CarbonImmutable|null $called_at
 * @property CarbonImmutable|null $consultation_started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $idempotency_key
 * @property-read WalkInQueue $queue
 */
class WalkInQueueEntry extends Model
{
    use HasUuids;

    protected $table = 'walkin_queue_entries';

    protected $guarded = ['*'];

    protected $attributes = ['triage_priority' => 0, 'recall_count' => 0];

    /** @var list<string> */
    protected $hidden = ['guest_name_encrypted', 'guest_phone_encrypted', 'guest_phone_hash', 'idempotency_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EntryStatus::class,
            'guest_name_encrypted' => 'encrypted',
            'guest_phone_encrypted' => 'encrypted',
            'guest_pii_purged_at' => 'immutable_datetime',
            'queue_number' => 'integer',
            'triage_priority' => 'integer',
            'recall_count' => 'integer',
            'estimated_call_at_min' => 'immutable_datetime',
            'estimated_call_at_max' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
            'consultation_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isGuest(): bool
    {
        return $this->patient_profile_id === null;
    }

    /** @return BelongsTo<WalkInQueue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(WalkInQueue::class, 'walkin_queue_id');
    }
}
