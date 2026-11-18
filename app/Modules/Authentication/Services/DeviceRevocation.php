<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\RefreshToken;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends sign-ins: one device, one refresh family, or every device of a user.
 */
final class DeviceRevocation
{
    public function revokeDevice(UserDevice $device): void
    {
        DB::transaction(function () use ($device) {
            PersonalAccessToken::where('user_device_id', $device->id)->delete();
            RefreshToken::where('user_device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            if (! $device->isRevoked()) {
                $device->forceFill(['revoked_at' => now()])->save();
            }
        });
    }

    public function revokeRefreshTokens(UserDevice $device): void
    {
        RefreshToken::where('user_device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** After a change of sign-in details: every other device; the current one stays signed in. */
    public function revokeOtherDevices(User $user, ?string $keepDeviceId): void
    {
        UserDevice::where('user_id', $user->id)->whereNull('revoked_at')
            ->when($keepDeviceId !== null, fn ($q) => $q->whereKeyNot($keepDeviceId))
            ->get()
            ->each(fn (UserDevice $device) => $this->revokeDevice($device));
    }

    /** Used after a password reset: every device, every token. */
    public function revokeEverything(User $user): void
    {
        UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->get()
            ->each(fn (UserDevice $device) => $this->revokeDevice($device));

        PersonalAccessToken::where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
    }
}
