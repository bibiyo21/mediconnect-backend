<?php

namespace App\Modules\Consultations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A user who took part in a consultation (database-schema.md §10).
 *
 * @property string $id
 * @property string $consultation_id
 * @property string $user_id
 * @property string $role
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $left_at
 */
class ConsultationParticipant extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }
}
