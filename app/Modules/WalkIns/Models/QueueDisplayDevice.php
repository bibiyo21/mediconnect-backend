<?php

namespace App\Modules\WalkIns\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A lobby screen bound to one clinic location by a hashed device token (D-31). It has no user account; the token is shown once.
 *
 * @property string $id
 * @property string $clinic_location_id
 * @property string $name
 * @property string $token_hash
 * @property string $registered_by
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 */
class QueueDisplayDevice extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
