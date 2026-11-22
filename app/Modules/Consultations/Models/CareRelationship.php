<?php

namespace App\Modules\Consultations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The basis on which a doctor may read a patient's records (CLAUDE.md §20.3, database-schema.md §17).
 * One row per source (appointment or walk-in entry); access ends at access_until or on revocation.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string $patient_profile_id
 * @property string $source_type
 * @property string $source_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $access_until
 * @property string $status
 * @property CarbonImmutable|null $revoked_at
 */
class CareRelationship extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'ACTIVE'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'access_until' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
