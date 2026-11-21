<?php

namespace App\Modules\Appointments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment that needs a new time after a schedule change, exception or check-out (§8.3). Created from Phase 4; worked from Phase 6.
 *
 * @property string $id
 * @property string $appointment_id
 * @property string $reason
 * @property string $status
 */
class ReschedulingTask extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
