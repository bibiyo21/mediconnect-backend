<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Models\RefreshToken;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Refresh token rotation with reuse detection (D-04).
 *
 * Every refresh token works once. Presenting a token that was already used
 * means it was copied or replayed, so the whole family (every token issued
 * from that sign-in) and the device's access tokens are revoked, and a HIGH
 * security event is recorded. There is deliberately no grace window: the
 * client must make sure only one refresh request runs at a time.
 */
final class RefreshTokenService
{
    public function __construct(
        private readonly SessionIssuer $issuer,
        private readonly DeviceRevocation $revocation,
        private readonly MfaService $mfa,
        private readonly RecordsSecurityEvents $securityEvents,
    ) {}

    public function rotate(string $plainRefreshToken): IssuedSession
    {
        $outcome = DB::transaction(function () use ($plainRefreshToken) {
            $token = RefreshToken::where('token_hash', hash('sha256', $plainRefreshToken))->lockForUpdate()->first();

            if ($token === null || $token->revoked_at !== null || $token->expires_at->isPast()) {
                return null;
            }

            $device = $token->device;

            if ($token->used_at !== null) {
                $this->revocation->revokeFamily($token->family_id);
                $this->revocation->revokeDevice($device);

                return ['reuse' => $device->user_id];
            }

            if ($device->isRevoked()) {
                return null;
            }

            /** @var User $user */
            $user = $device->user;

            if (! $user->isActive()) {
                return null;
            }

            $fullAccess = $this->mfa->accessIsFull($user, mfaJustVerified: false, wasFull: true);
            $session = $this->issuer->rotateTokens($device, $user, $token->family_id, $fullAccess);

            $token->forceFill(['used_at' => now()])->save();
            $device->forceFill(['last_seen_at' => now()])->save();

            return ['session' => $session];
        });

        if (isset($outcome['reuse'])) {
            // The revocations above are already committed; recording happens after the transaction.
            $this->securityEvents->record(SecurityEventType::RefreshTokenReuse, $outcome['reuse']);
        }

        return $outcome['session'] ?? throw AuthFailure::invalidRefreshToken();
    }
}
