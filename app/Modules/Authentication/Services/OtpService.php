<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Enums\OtpStatus;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Models\OtpChallenge;
use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;
use App\Shared\Services\Fingerprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time codes (§4.1). The code is generated server-side, kept only as a
 * keyed hash in Redis (`otp:{challenge}`) and expires after a few minutes.
 * A correct code yields a short-lived, single-use verification token that the
 * follow-up action (register, reset password, verify contact) consumes.
 */
final class OtpService
{
    public function __construct(
        private readonly SendsOneTimeMessages $messages,
        private readonly RecordsSecurityEvents $securityEvents,
    ) {}

    /**
     * Creates a challenge. When $deliverCode is false (for example the account
     * does not exist, or already exists for registration), the challenge looks
     * identical to the caller but no code exists, so it can never be verified.
     */
    public function issue(OtpPurpose $purpose, Destination $destination, ?string $userId, bool $deliverCode, ?string $notice = null): OtpChallenge
    {
        $this->throttleDestination($destination);

        $config = config('authentication.otp');
        $challenge = OtpChallenge::create([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'channel' => $destination->channel,
            'purpose' => $purpose,
            'destination_hash' => $destination->hash(),
            'destination_masked' => $destination->masked(),
            'status' => OtpStatus::Pending,
            'expires_at' => now()->addMinutes($config['ttl_minutes']),
        ]);

        if ($deliverCode) {
            $code = $this->generateCode((int) $config['length']);
            Cache::put($this->cacheKey($challenge), $this->codeHash($challenge, $code), now()->addMinutes($config['ttl_minutes']));
            $this->messages->sendCode($destination->channel, $destination->value, $code, (int) $config['ttl_minutes']);
        } elseif ($notice !== null) {
            $this->messages->sendNotice($destination->channel, $destination->value, $notice);
        }

        return $challenge;
    }

    /**
     * Checks a code. Returns the plain verification token (shown once).
     */
    public function verify(string $challengeId, string $code): string
    {
        $config = config('authentication.otp');

        $result = DB::transaction(function () use ($challengeId, $code, $config) {
            $challenge = OtpChallenge::where('public_id', $challengeId)->lockForUpdate()->first();

            if ($challenge === null || $challenge->status !== OtpStatus::Pending || $challenge->expires_at->isPast()) {
                return null;
            }

            $stored = Cache::get($this->cacheKey($challenge));

            if (! is_string($stored) || ! hash_equals($stored, $this->codeHash($challenge, $code))) {
                $challenge->attempts++;

                if ($challenge->attempts >= $config['max_attempts']) {
                    $challenge->status = OtpStatus::Failed;
                    Cache::forget($this->cacheKey($challenge));
                    $this->securityEvents->record(SecurityEventType::OtpLocked, $challenge->user_id, ['purpose' => $challenge->purpose->value]);
                }

                $challenge->save();

                return null;
            }

            $token = Str::random(64);
            $challenge->forceFill([
                'status' => OtpStatus::Verified,
                'verified_at' => now(),
                'verification_token_hash' => hash('sha256', $token),
                'verification_token_expires_at' => now()->addMinutes($config['verification_token_minutes']),
            ])->save();
            Cache::forget($this->cacheKey($challenge));

            return $token;
        });

        return $result ?? throw AuthFailure::invalidCode();
    }

    /**
     * Uses up a verification token for the given purpose and destination.
     * Must be called inside the caller's transaction.
     */
    public function consume(string $verificationToken, OtpPurpose $purpose, Destination $destination): OtpChallenge
    {
        $challenge = OtpChallenge::where('verification_token_hash', hash('sha256', $verificationToken))->lockForUpdate()->first();

        $valid = $challenge !== null
            && $challenge->status === OtpStatus::Verified
            && $challenge->purpose === $purpose
            && $challenge->channel === $destination->channel
            && hash_equals($challenge->destination_hash, $destination->hash())
            && $challenge->verification_token_expires_at?->isFuture();

        if (! $valid) {
            throw AuthFailure::invalidVerificationToken();
        }

        $challenge->forceFill(['status' => OtpStatus::Consumed, 'consumed_at' => now()])->save();

        return $challenge;
    }

    private function throttleDestination(Destination $destination): void
    {
        $config = config('authentication.otp');
        $key = 'otp-destination:'.$destination->hash();

        if (RateLimiter::tooManyAttempts($key, (int) $config['per_destination_limit'])) {
            throw new class extends DomainException
            {
                public function errorCode(): ErrorCode
                {
                    return ErrorCode::RateLimited;
                }
            };
        }

        RateLimiter::hit($key, (int) $config['per_destination_window_minutes'] * 60);
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function cacheKey(OtpChallenge $challenge): string
    {
        return 'otp:'.$challenge->public_id;
    }

    private function codeHash(OtpChallenge $challenge, string $code): string
    {
        return Fingerprint::of('otp:'.$challenge->public_id.':'.$code);
    }
}
