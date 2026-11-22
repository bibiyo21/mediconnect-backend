<?php

namespace App\Modules\Presence\Models;

use App\Modules\Presence\Enums\PresenceStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of every presence change with the CLAUDE.md §8.5 fields (a database trigger forbids updates and deletes).
 *
 * @property int $id
 * @property string $doctor_presence_session_id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property PresenceStatus $previous_status
 * @property PresenceStatus $new_status
 * @property string|null $changed_by
 * @property string $change_method
 * @property string|null $reason
 * @property string|null $source_device_id
 * @property array<string, mixed>|null $metadata
 */
class DoctorPresenceEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'previous_status' => PresenceStatus::class,
            'new_status' => PresenceStatus::class,
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
