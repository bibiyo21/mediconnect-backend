<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Models\MfaFactor;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP multi-factor authentication (§4.1, rbac-permissions.md §2 and §7).
 *
 * - A code is accepted at most once: the last accepted time step is stored and
 *   older or equal steps are refused, so a code cannot be replayed within its
 *   30-second window.
 * - Verification locks the factor row, so two simultaneous requests with the
 *   same code cannot both pass.
 * - Recovery codes are single-use and stored only as hashes.
 */
final class MfaService
{
    private const WINDOW = 1; // accept the previous/next 30-second step for clock drift

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly RecordsSecurityEvents $securityEvents,
        private readonly Clock $clock,
    ) {}

    public function isEnrolled(User $user): bool
    {
        return MfaFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->exists();
    }

    public function isRequiredByRole(User $user): bool
    {
        return $user->getRoleNames()->contains(fn (string $name) => Role::tryFrom($name)?->requiresMfa() === true);
    }

    /**
     * Whether a new or rotated session gets full access.
     */
    public function accessIsFull(User $user, bool $mfaJustVerified, bool $wasFull = false): bool
    {
        if ($this->isEnrolled($user)) {
            return $mfaJustVerified || $wasFull;
        }

        return ! $this->isRequiredByRole($user);
    }

    /** @return array{secret: string, otpauth_uri: string} */
    public function startEnrollment(User $user): array
    {
        if ($this->isEnrolled($user)) {
            throw AuthFailure::mfaAlreadyEnabled();
        }

        $secret = $this->google2fa->generateSecretKey(32);

        MfaFactor::updateOrCreate(
            ['user_id' => $user->id, 'factor_type' => 'TOTP'],
            ['secret_encrypted' => $secret, 'confirmed_at' => null, 'recovery_codes_hashed' => null, 'last_used_timestep' => null],
        );

        $label = $user->email ?? $user->mobile_e164 ?? $user->id;

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->google2fa->getQRCodeUrl((string) config('authentication.mfa.issuer'), (string) $label, $secret),
        ];
    }

    /** @return list<string> plain recovery codes, shown once */
    public function confirmEnrollment(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code) {
            $factor = MfaFactor::where('user_id', $user->id)->whereNull('confirmed_at')->lockForUpdate()->first();

            if ($factor === null || ! $this->acceptTotp($factor, $code)) {
                throw AuthFailure::invalidCode();
            }

            $codes = $this->newRecoveryCodes();
            $factor->forceFill([
                'confirmed_at' => now(),
                'recovery_codes_hashed' => array_map(fn (string $c) => Hash::make($c), $codes),
            ])->save();

            $this->securityEvents->record(SecurityEventType::MfaEnrolled, $user->id);

            return $codes;
        });
    }

    /**
     * Verifies a TOTP code or a recovery code for an enrolled user.
     */
    public function verify(User $user, ?string $code, ?string $recoveryCode): bool
    {
        $ok = DB::transaction(function () use ($user, $code, $recoveryCode) {
            $factor = MfaFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();

            if ($factor === null) {
                return false;
            }

            if ($code !== null && $code !== '') {
                return $this->acceptTotp($factor, $code);
            }

            if ($recoveryCode !== null && $recoveryCode !== '') {
                return $this->acceptRecoveryCode($factor, $recoveryCode, $user);
            }

            return false;
        });

        if (! $ok) {
            $this->securityEvents->record(SecurityEventType::MfaFailed, $user->id);
        }

        return $ok;
    }

    private function acceptTotp(MfaFactor $factor, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        // Always pass a previous step (0 the first time): with null, google2fa returns
        // `true` instead of the matched step, and storing "now" could let a code that
        // matched the next step be accepted twice.
        // The injected clock (not the raw system time) decides the current step, so time is testable.
        $now = intdiv($this->clock->now()->getTimestamp(), $this->google2fa->getKeyRegeneration());
        $timestep = $this->google2fa->verifyKeyNewer($factor->secret_encrypted, $code, $factor->last_used_timestep ?? 0, self::WINDOW, $now);

        if (! is_int($timestep)) {
            return false;
        }

        $factor->forceFill(['last_used_timestep' => $timestep, 'last_used_at' => now()])->save();

        return true;
    }

    private function acceptRecoveryCode(MfaFactor $factor, string $recoveryCode, User $user): bool
    {
        $recoveryCode = strtolower(trim($recoveryCode));
        $hashes = $factor->recovery_codes_hashed ?? [];

        foreach ($hashes as $index => $hash) {
            if (Hash::check($recoveryCode, $hash)) {
                unset($hashes[$index]);
                $factor->forceFill(['recovery_codes_hashed' => array_values($hashes), 'last_used_at' => now()])->save();
                $this->securityEvents->record(SecurityEventType::RecoveryCodeUsed, $user->id, ['remaining' => count($hashes)]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            fn () => strtolower(Str::random(5).'-'.Str::random(5)),
            range(1, (int) config('authentication.mfa.recovery_codes')),
        );
    }
}
