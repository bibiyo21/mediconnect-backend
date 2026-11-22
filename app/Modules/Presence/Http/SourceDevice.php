<?php

namespace App\Modules\Presence\Http;

use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;

/**
 * The device a request comes from (CLAUDE.md §8.5 "source device"), taken from the
 * authenticated session, never from a client-sent value: the native token's device, or the
 * web session's device. Null when there is none or it was signed out.
 */
final class SourceDevice
{
    public static function of(Request $request): ?string
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->user_device_id : ($request->hasSession() ? $request->session()->get('auth.device_id') : null);
        if (! is_string($deviceId) || $deviceId === '') {
            return null;
        }

        return UserDevice::where('user_id', $user->id)->whereKey($deviceId)->whereNull('revoked_at')->exists() ? $deviceId : null;
    }
}
