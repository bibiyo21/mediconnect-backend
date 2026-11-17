<?php

namespace App\Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum access token bound to the device it was issued to (D-04).
 *
 * @property string|null $user_device_id
 * @property-read UserDevice|null $device
 */
class PersonalAccessToken extends SanctumToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'user_device_id'];

    /** @return BelongsTo<UserDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }
}
