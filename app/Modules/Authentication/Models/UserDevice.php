<?php

namespace App\Modules\Authentication\Models;

use App\Modules\Authentication\Enums\DevicePlatform;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed-in device or browser (D-15). Revoking it ends every token issued to it.
 */
class UserDevice extends Model
{
    use HasUuids;

    protected $fillable = ['platform', 'device_name', 'app_version', 'last_seen_at', 'last_ip_hash'];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Read-only convenience across modules (architecture.md §6.3 rule 2).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
