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
 *   (revoking a device in "my devices" ends that browser's session too), and the device's
 *   last_seen_at is refreshed (throttled) so idle browser sessions can be told from live ones.
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

            // Heartbeat for the realtime sweep: a browser session is live while its device keeps being seen.
            // At most one write per device per 5 minutes.
            if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(5))) {
                UserDevice::whereKey($device->id)->update(['last_seen_at' => now()]);
            }
        }

        return $next($request);
    }
}
