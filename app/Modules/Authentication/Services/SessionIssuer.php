<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Enums\DevicePlatform;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\RefreshToken;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Users\Models\User;
use App\Shared\Services\Fingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Turns a successful authentication into a signed-in device (D-04):
 * - web clients get a Sanctum session cookie;
 * - native clients get a short-lived access token and a rotating refresh token.
 *
 * $fullAccess = false is used when MFA is required but not set up yet: the
 * client may only reach the MFA enrollment endpoints, and gets no refresh token.
 */
final class SessionIssuer
{
    public const FULL = ['*'];

    public const ENROLLMENT_ONLY = ['mfa:enroll'];

    /**
     * @param  array{platform?: string|null, name?: string|null, app_version?: string|null}  $deviceInfo
     */
    public function signIn(Request $request, User $user, array $deviceInfo, bool $fullAccess): IssuedSession
    {
        return $this->isWebClient($request)
            ? $this->startWebSession($request, $user, $fullAccess)
            : $this->issueNativeTokens($request, $user, $deviceInfo, $fullAccess);
    }

    public function isWebClient(Request $request): bool
    {
        return EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    /**
     * New token pair for a device whose previous refresh token was just rotated.
     */
    public function rotateTokens(UserDevice $device, User $user, string $familyId, bool $fullAccess): IssuedSession
    {
        return DB::transaction(function () use ($device, $user, $familyId, $fullAccess) {
            PersonalAccessToken::where('user_device_id', $device->id)->delete();

            return $this->tokensFor($device, $user, $fullAccess, $familyId);
        });
    }

    private function startWebSession(Request $request, User $user, bool $fullAccess): IssuedSession
    {
        $device = $this->createDevice($request, $user, ['platform' => DevicePlatform::Web->value]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.device_id', $device->id);
        $request->session()->put('auth.mfa_satisfied', $fullAccess);

        return new IssuedSession($device, web: true, fullAccess: $fullAccess);
    }

    /** @param array{platform?: string|null, name?: string|null, app_version?: string|null} $deviceInfo */
    private function issueNativeTokens(Request $request, User $user, array $deviceInfo, bool $fullAccess): IssuedSession
    {
        return DB::transaction(function () use ($request, $user, $deviceInfo, $fullAccess) {
            $device = $this->createDevice($request, $user, $deviceInfo);

            return $this->tokensFor($device, $user, $fullAccess, (string) Str::uuid7());
        });
    }

    private function tokensFor(UserDevice $device, User $user, bool $fullAccess, string $familyId): IssuedSession
    {
        $accessExpiresAt = CarbonImmutable::now()->addMinutes((int) config('authentication.access_token_minutes'));
        $newToken = $user->createToken('device:'.$device->id, $fullAccess ? self::FULL : self::ENROLLMENT_ONLY, $accessExpiresAt);
        $newToken->accessToken->forceFill(['user_device_id' => $device->id])->save();

        $refreshPlain = null;
        $refreshExpiresAt = null;

        if ($fullAccess) {
            $refreshPlain = Str::random(64);
            $refreshExpiresAt = CarbonImmutable::now()->addDays((int) config('authentication.refresh_token_days'));
            RefreshToken::create([
                'user_device_id' => $device->id,
                'token_hash' => hash('sha256', $refreshPlain),
                'family_id' => $familyId,
                'expires_at' => $refreshExpiresAt,
            ]);
        }

        return new IssuedSession(
            $device,
            web: false,
            fullAccess: $fullAccess,
            accessToken: $newToken->plainTextToken,
            accessTokenExpiresAt: $accessExpiresAt,
            refreshToken: $refreshPlain,
            refreshTokenExpiresAt: $refreshExpiresAt,
        );
    }

    /** @param array{platform?: string|null, name?: string|null, app_version?: string|null} $deviceInfo */
    private function createDevice(Request $request, User $user, array $deviceInfo): UserDevice
    {
        $device = new UserDevice([
            'platform' => $deviceInfo['platform'] ?? DevicePlatform::Web->value,
            'device_name' => isset($deviceInfo['name']) ? mb_substr((string) $deviceInfo['name'], 0, 100) : null,
            'app_version' => isset($deviceInfo['app_version']) ? mb_substr((string) $deviceInfo['app_version'], 0, 40) : null,
            'last_seen_at' => now(),
            'last_ip_hash' => Fingerprint::ip($request->ip()),
        ]);
        $device->user()->associate($user);
        $device->save();

        return $device;
    }
}
