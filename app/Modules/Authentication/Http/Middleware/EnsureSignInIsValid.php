<?php

namespace App\Modules\Authentication\Http\Middleware;

use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum on every protected route:
 * - the account must still be ACTIVE (a suspension takes effect immediately);
 * - a web session must belong to a device that has not been signed out
 *   (revoking a device in "my devices" ends that browser's session too).
 */
final class EnsureSignInIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            throw AuthFailure::invalidCredentials();
        }

        if (! $user->isActive()) {
            throw AuthFailure::accountSuspended();
        }

        if (! $user->currentAccessToken() instanceof PersonalAccessToken && $request->hasSession()) {
            $deviceId = $request->session()->get('auth.device_id');
            $device = $deviceId ? UserDevice::where('user_id', $user->id)->whereKey($deviceId)->first() : null;

            if ($device === null || $device->isRevoked()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();

                throw AuthFailure::invalidRefreshToken();
            }
        }

        return $next($request);
    }
}
