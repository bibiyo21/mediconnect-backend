<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Http\Requests\ForgotPasswordRequest;
use App\Modules\Authentication\Http\Requests\RequestOtpRequest;
use App\Modules\Authentication\Http\Requests\VerifyOtpRequest;
use App\Modules\Authentication\Models\OtpChallenge;
use App\Modules\Authentication\Services\Destination;
use App\Modules\Authentication\Services\OtpService;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Enums\DeliveryChannel;
use App\Shared\Exceptions\InvalidValue;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/otp, POST /auth/otp/verify, POST /auth/forgot-password.
 *
 * Every request for a code gets the same 202 response whether or not an
 * account uses the address, so these endpoints cannot be used to find accounts.
 */
final class OtpController
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Accounts $accounts,
    ) {}

    public function request(RequestOtpRequest $request): JsonResponse
    {
        $purpose = $request->purpose();

        if ($purpose->requiresAuthentication()) {
            return $this->forOwnContact($purpose, $request->user('sanctum'));
        }

        $destination = $request->destination();
        $existing = $this->findAccount($destination);

        $challenge = match ($purpose) {
            OtpPurpose::Register => $this->otp->issue($purpose, $destination, null, deliverCode: $existing === null,
                notice: $existing === null ? null : $this->alreadyRegisteredNotice($destination)),
            OtpPurpose::ResetPassword => $this->otp->issue($purpose, $destination, $existing?->id,
                deliverCode: $existing !== null && $existing->isActive()),
            default => throw new \LogicException('Handled above: purposes that need a signed-in user.'),
        };

        return $this->accepted($challenge);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $destination = $request->destination();
        $existing = $this->findAccount($destination);

        $challenge = $this->otp->issue(OtpPurpose::ResetPassword, $destination, $existing?->id,
            deliverCode: $existing !== null && $existing->isActive());

        return $this->accepted($challenge);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $token = $this->otp->verify((string) $request->input('challenge_id'), (string) $request->input('code'));

        return ApiResponse::success([
            'verification_token' => $token,
            'expires_in_minutes' => (int) config('authentication.otp.verification_token_minutes'),
        ]);
    }

    private function forOwnContact(OtpPurpose $purpose, ?User $user): JsonResponse
    {
        if ($user === null) {
            throw AuthFailure::invalidCredentials();
        }

        $destination = match ($purpose) {
            OtpPurpose::VerifyEmail => $user->email ? Destination::email($user->email) : null,
            default => $user->mobile_e164 ? Destination::mobile($user->mobile_e164) : null,
        };

        if ($destination === null) {
            throw new InvalidValue('There is no address of this kind on your account.');
        }

        $alreadyVerified = $purpose === OtpPurpose::VerifyEmail ? $user->email_verified_at !== null : $user->mobile_verified_at !== null;

        return $this->accepted($this->otp->issue($purpose, $destination, $user->id, deliverCode: ! $alreadyVerified));
    }

    private function findAccount(Destination $destination): ?User
    {
        return $destination->channel === DeliveryChannel::Email
            ? $this->accounts->findByEmail($destination->value)
            : $this->accounts->findByMobile($destination->phoneNumber() ?? throw new \LogicException('mobile destination without number'));
    }

    private function alreadyRegisteredNotice(Destination $destination): string
    {
        $what = $destination->channel === DeliveryChannel::Email ? 'email address' : 'mobile number';

        return "Someone tried to create a MediConnect account with this {$what}, but an account already uses it. If this was you, sign in or reset your password. If not, you can ignore this message.";
    }

    private function accepted(OtpChallenge $challenge): JsonResponse
    {
        return ApiResponse::success([
            'challenge_id' => $challenge->public_id,
            'channel' => $challenge->channel->value,
            'destination_masked' => $challenge->destination_masked,
            'expires_at' => $challenge->expires_at->toIso8601ZuluString(),
        ], message: 'If the address can receive a code, one has been sent.', status: 202);
    }
}
