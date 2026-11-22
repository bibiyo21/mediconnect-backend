<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\RevokesSessions;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\RefreshToken;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Notifications\Contracts\TerminatesRealtimeSessions;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends sign-ins: one device, one refresh family, or every device of a user.
 */
final class DeviceRevocation implements RevokesSessions
{
    public function __construct(private readonly TerminatesRealtimeSessions $realtime) {}

    /**
     * Revoking a device also cuts the user's live WebSocket connections (M-3), after commit. Reverb
     * ties a connection to the user, not the device, so the user's other devices reconnect and
     * re-authorize; only the revoked device fails to. A caller that must end something else first
     * (a web session, which still authenticates a reconnecting socket) passes $terminateConnections
     * false and calls the realtime contract itself afterwards.
     */
    public function revokeDevice(UserDevice $device, bool $terminateConnections = true): void
    {
        DB::transaction(function () use ($device, $terminateConnections) {
            $this->endDevice($device);
            if ($terminateConnections) {
                $this->realtime->terminateForUser($device->user_id, 'device_revoked');
            }
        });
    }

    private function endDevice(UserDevice $device): void
    {
        PersonalAccessToken::where('user_device_id', $device->id)->delete();
        RefreshToken::where('user_device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        if (! $device->isRevoked()) {
            $device->forceFill(['revoked_at' => now()])->save();
        }
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
        DB::transaction(function () use ($user, $keepDeviceId) {
            UserDevice::where('user_id', $user->id)->whereNull('revoked_at')
                ->when($keepDeviceId !== null, fn ($q) => $q->whereKeyNot($keepDeviceId))
                ->get()
                ->each(fn (UserDevice $device) => $this->endDevice($device));
            $this->realtime->terminateForUser($user->id, 'other_devices_revoked');
        });
    }

    /** Used after a password reset, a suspension or a deletion request: every device, every token, every live connection. */
    public function revokeEverything(User $user): void
    {
        DB::transaction(function () use ($user) {
            UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->get()
                ->each(fn (UserDevice $device) => $this->endDevice($device));

            PersonalAccessToken::where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
            // One termination for the whole user, also when only a web session (no device row) exists.
            $this->realtime->terminateForUser($user->id, 'sessions_revoked');
        });
    }
}
