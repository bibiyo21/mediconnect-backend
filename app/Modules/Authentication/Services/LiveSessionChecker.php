<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\LiveSessions;
use App\Modules\Authentication\Enums\DevicePlatform;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credential check for the realtime sweep. A user is live when the account is ACTIVE and one of:
 *
 * - a FULL-ability (`["*"]`) unexpired access token on a non-revoked device (an MFA-enrolment-only
 *   token cannot call the API, so it does not keep a socket);
 * - an unused, unrevoked, unexpired refresh token on a non-revoked device;
 * - a non-revoked WEB device seen within the web session lifetime plus a margin. A WEB device is
 *   created only by the cookie sign-in (token sign-ins must name IOS or ANDROID, DeviceRules), and
 *   EnsureSignInIsValid refreshes its last_seen_at on authenticated requests (at most every 5
 *   minutes). Redis sessions have no user index, so this is the readable proxy: a browser idle for
 *   session.lifetime + MARGIN_MINUTES is treated as signed out. Every logout, device revocation and
 *   suspension also revokes the device.
 *
 * A passive page with no REST traffic for that long therefore loses its socket; the client must
 * refetch and re-authenticate on reconnect.
 */
final class LiveSessionChecker implements LiveSessions
{
    /** Slack over the session lifetime for the heartbeat throttle (5 minutes) and clock skew. */
    public const MARGIN_MINUTES = 5;

    private const CHUNK = 1000;

    public function liveUserIds(array $userIds): array
    {
        $live = [];
        foreach (array_chunk(array_values(array_unique($userIds)), self::CHUNK) as $chunk) {
            foreach ($this->liveInChunk($chunk) as $id) {
                $live[$id] = true;
            }
        }

        return array_map('strval', array_keys($live));
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function liveInChunk(array $userIds): array
    {
        $now = now();

        $active = DB::table('users')->whereIn('id', $userIds)->where('status', UserStatus::Active->value)->pluck('id')->all();
        if ($active === []) {
            return [];
        }

        $viaAccessToken = DB::table('personal_access_tokens as t')
            ->join('user_devices as d', 'd.id', '=', 't.user_device_id')
            ->where('t.tokenable_type', (new User)->getMorphClass())
            ->whereIn('t.tokenable_id', $active)
            ->where('t.abilities', json_encode(['*']))
            ->whereNull('d.revoked_at')
            ->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', $now))
            ->pluck('t.tokenable_id')->all();

        $viaRefreshToken = DB::table('auth_refresh_tokens as r')
            ->join('user_devices as d', 'd.id', '=', 'r.user_device_id')
            ->whereIn('d.user_id', $active)
            ->whereNull('d.revoked_at')
            ->whereNull('r.used_at')->whereNull('r.revoked_at')->where('r.expires_at', '>', $now)
            ->pluck('d.user_id')->all();

        $webCutoff = $now->copy()->subMinutes((int) config('session.lifetime') + self::MARGIN_MINUTES);
        $viaWebDevice = DB::table('user_devices')
            ->whereIn('user_id', $active)->where('platform', DevicePlatform::Web->value)->whereNull('revoked_at')
            ->where('last_seen_at', '>', $webCutoff)
            ->pluck('user_id')->all();

        return array_values(array_map('strval', array_unique(array_merge($viaAccessToken, $viaRefreshToken, $viaWebDevice))));
    }
}
