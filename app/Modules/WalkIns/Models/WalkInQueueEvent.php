<?php

namespace App\Modules\WalkIns\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of every queue and entry change (a database trigger forbids updates and deletes).
 *
 * @property int $id
 * @property string $walkin_queue_id
 * @property string|null $walkin_queue_entry_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 */
class WalkInQueueEvent extends Model
{
    protected $table = 'walkin_queue_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
