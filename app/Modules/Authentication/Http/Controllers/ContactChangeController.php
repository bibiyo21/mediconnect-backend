<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Http\Requests\ConfirmContactChangeRequest;
use App\Modules\Authentication\Http\Requests\RequestContactChangeCodeRequest;
use App\Modules\Authentication\Http\Resources\AccountResource;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Services\Destination;
use App\Modules\Authentication\Services\DeviceRevocation;
use App\Modules\Authentication\Services\OtpService;
use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Enums\DeliveryChannel;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * POST /auth/contact/otp and PUT /auth/contact: add or change the email address
 * or mobile number used to sign in. Both are sign-in identifiers, so the change
 * needs the current password and a code sent to the new address. Afterwards
 * the old address is told, and every other device is signed out.
 */
final class ContactChangeController
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Accounts $accounts,
        private readonly SendsOneTimeMessages $messages,
    ) {}

    public function requestCode(RequestContactChangeCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The password is incorrect.']]);
        }

        $destination = $request->destination();
        $field = $destination->channel === DeliveryChannel::Email ? 'email' : 'mobile';

        if ($this->currentValue($user, $destination->channel) === $destination->value) {
            throw ValidationException::withMessages([$field => ['This is already on your account.']]);
        }

        // An address that belongs to someone else gets the same answer but never a code,
        // so this endpoint cannot be used to discover accounts. Its owner is told.
        $takenBy = $destination->channel === DeliveryChannel::Email
            ? $this->accounts->findByEmail($destination->value)
            : $this->accounts->findByMobile($destination->phoneNumber() ?? throw new \LogicException('mobile without number'));

        $challenge = $this->otp->issue($this->purpose($destination), $destination, $user->id, deliverCode: $takenBy === null,
            notice: $takenBy === null ? null : 'Someone tried to add this '.($field === 'email' ? 'email address' : 'mobile number').' to a different MediConnect account. Your account is unchanged; you can ignore this message.');

        return ApiResponse::success([
            'challenge_id' => $challenge->public_id,
            'channel' => $challenge->channel->value,
            'destination_masked' => $challenge->destination_masked,
            'expires_at' => $challenge->expires_at->toIso8601ZuluString(),
        ], message: 'If the address can receive a code, one has been sent.', status: 202);
    }

    public function confirm(ConfirmContactChangeRequest $request, DeviceRevocation $revocation, RecordsSecurityEvents $events): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $destination = $request->destination();
        $previous = $this->currentValue($user, $destination->channel);

        try {
            DB::transaction(function () use ($user, $destination, $request) {
                $challenge = $this->otp->consume((string) $request->input('verification_token'), $this->purpose($destination), $destination);

                if ($challenge->user_id !== $user->id) {
                    throw AuthFailure::invalidVerificationToken();
                }

                $destination->channel === DeliveryChannel::Email
                    ? $this->accounts->changeEmail($user, $destination->value)
                    : $this->accounts->changeMobile($user, $destination->phoneNumber() ?? throw new \LogicException('mobile without number'));
            });
        } catch (UniqueConstraintViolationException) {
            // Taken by another account between the code request and now.
            $field = $destination->channel === DeliveryChannel::Email ? 'email' : 'mobile';
            throw ValidationException::withMessages([$field => ['This address cannot be used. Try a different one.']]);
        }

        $revocation->revokeOtherDevices($user, $this->currentDeviceId($request));
        $events->record(SecurityEventType::ContactChanged, $user->id, ['channel' => $destination->channel->value, 'added' => $previous === null]);

        if ($previous !== null) {
            $what = $destination->channel === DeliveryChannel::Email ? 'email address' : 'mobile number';
            $this->messages->sendNotice($destination->channel, $previous,
                "The {$what} on your MediConnect account was changed, and your other devices were signed out. If this was not you, reset your password right away.");
        }

        return ApiResponse::success(new AccountResource($user->refresh()->load('profile')), message: 'Your contact details were updated.');
    }

    private function purpose(Destination $destination): OtpPurpose
    {
        return $destination->channel === DeliveryChannel::Email ? OtpPurpose::ChangeEmail : OtpPurpose::ChangeMobile;
    }

    private function currentValue(User $user, DeliveryChannel $channel): ?string
    {
        return $channel === DeliveryChannel::Email ? ($user->email !== null ? mb_strtolower($user->email) : null) : $user->mobile_e164;
    }

    private function currentDeviceId(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->user_device_id;
        }

        $deviceId = $request->hasSession() ? $request->session()->get('auth.device_id') : null;

        return is_string($deviceId) ? $deviceId : null;
    }
}
