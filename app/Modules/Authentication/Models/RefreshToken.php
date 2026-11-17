<?php

namespace App\Modules\Authentication\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rotating, single-use refresh token for native clients (D-04). Only the
 * SHA-256 hash is stored.
 *
 * @property string $user_device_id
 * @property string $family_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read UserDevice $device
 */
class RefreshToken extends Model
{
    use HasUuids;

    protected $table = 'auth_refresh_tokens';

    public const UPDATED_AT = null;

    protected $fillable = ['user_device_id', 'token_hash', 'family_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UserDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }
}
