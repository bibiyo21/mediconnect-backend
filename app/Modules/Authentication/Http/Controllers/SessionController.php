<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Authentication\Actions\AttemptLogin;
use App\Modules\Authentication\Actions\CompleteMfaLogin;
use App\Modules\Authentication\Http\Requests\DeviceRules;
use App\Modules\Authentication\Http\Requests\LoginRequest;
use App\Modules\Authentication\Http\Requests\MfaLoginRequest;
use App\Modules\Authentication\Http\Requests\RefreshRequest;
use App\Modules\Authentication\Http\Resources\AccountResource;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Authentication\Services\DeviceRevocation;
use App\Modules\Authentication\Services\IssuedSession;
use App\Modules\Authentication\Services\RefreshTokenService;
use App\Modules\Notifications\Contracts\TerminatesRealtimeSessions;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST /auth/login, /auth/mfa/verify, /auth/refresh, /auth/logout; GET /auth/me.
 */
final class SessionController
{
    public function login(LoginRequest $request, AttemptLogin $login): JsonResponse
    {
        $result = $login->handle($request, (string) $request->input('identifier'), (string) $request->input('password'), DeviceRules::from($request));

        if (is_array($result)) {
            return ApiResponse::success([
                'mfa_required' => true,
                'mfa_challenge_id' => $result['id'],
                'mfa_challenge_expires_at' => $result['expires_at'],
            ], message: 'Enter the code from your authenticator app.');
        }

        return $this->signedIn($request, $result);
    }

    public function completeMfa(MfaLoginRequest $request, CompleteMfaLogin $complete): JsonResponse
    {
        $session = $complete->handle(
            $request,
            (string) $request->input('mfa_challenge_id'),
            $request->input('code'),
            $request->input('recovery_code'),
        );

        return $this->signedIn($request, $session);
    }

    public function refresh(RefreshRequest $request, RefreshTokenService $refresh): JsonResponse
    {
        $session = $refresh->rotate((string) $request->input('refresh_token'));

        return ApiResponse::success($session->toArray());
    }

    public function logout(Request $request, DeviceRevocation $revocation, TerminatesRealtimeSessions $realtime): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        // Every branch cuts the user's live WebSocket connections after commit (M-3).
        if ($token instanceof PersonalAccessToken) {
            // The token is deleted before the connections are cut, so a reconnect cannot re-authorize.
            if ($token->device) {
                $revocation->revokeDevice($token->device);
            } else {
                $token->delete();
                $realtime->terminateForUser($user->id, 'logout');
            }
        } else {
            $deviceId = $request->session()->get('auth.device_id');
            $device = $deviceId ? UserDevice::where('user_id', $user->id)->whereKey($deviceId)->first() : null;
            $device && $revocation->revokeDevice($device, terminateConnections: false);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // After the session is gone: while it lives, a socket that reconnects right after being cut
            // would authorize again with the same cookie.
            $realtime->terminateForUser($user->id, 'logout');
        }

        return ApiResponse::success(message: 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new AccountResource($user->load('profile')));
    }

    private function signedIn(Request $request, IssuedSession $session): JsonResponse
    {
        /** @var User $user */
        $user = $session->device->user;

        return ApiResponse::success([
            'user' => new AccountResource($user->load('profile')),
            ...$session->toArray(),
        ]);
    }
}
