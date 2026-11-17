<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Http\Requests\VerificationTokenRequest;
use App\Modules\Authentication\Services\Destination;
use App\Modules\Authentication\Services\OtpService;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** POST /auth/verify-email, POST /auth/verify-mobile (the user's own contact details). */
final class ContactVerificationController
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Accounts $accounts,
    ) {}

    public function email(VerificationTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->confirm($user, OtpPurpose::VerifyEmail, $user->email ? Destination::email($user->email) : null, (string) $request->input('verification_token'));

        return ApiResponse::success(message: 'Your email address is verified.');
    }

    public function mobile(VerificationTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->confirm($user, OtpPurpose::VerifyMobile, $user->mobile_e164 ? Destination::mobile($user->mobile_e164) : null, (string) $request->input('verification_token'));

        return ApiResponse::success(message: 'Your mobile number is verified.');
    }

    private function confirm(User $user, OtpPurpose $purpose, ?Destination $destination, string $token): void
    {
        if ($destination === null) {
            throw AuthFailure::invalidVerificationToken();
        }

        DB::transaction(function () use ($user, $purpose, $destination, $token) {
            $challenge = $this->otp->consume($token, $purpose, $destination);

            if ($challenge->user_id !== $user->id) {
                throw AuthFailure::invalidVerificationToken();
            }

            $purpose === OtpPurpose::VerifyEmail ? $this->accounts->markEmailVerified($user) : $this->accounts->markMobileVerified($user);
        });
    }
}
