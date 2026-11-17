<?php

namespace App\Modules\Authentication\Actions;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Services\Destination;
use App\Modules\Authentication\Services\DeviceRevocation;
use App\Modules\Authentication\Services\OtpService;
use App\Modules\Users\Contracts\Accounts;
use App\Shared\Enums\DeliveryChannel;
use Illuminate\Support\Facades\DB;

/**
 * Sets a new password with a verified reset code, then ends every session and
 * token of the account (§4.1).
 */
final class ResetPassword
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Accounts $accounts,
        private readonly DeviceRevocation $revocation,
        private readonly RecordsSecurityEvents $securityEvents,
    ) {}

    public function handle(Destination $destination, string $verificationToken, string $password): void
    {
        $userId = DB::transaction(function () use ($destination, $verificationToken, $password) {
            $this->otp->consume($verificationToken, OtpPurpose::ResetPassword, $destination);

            $user = $destination->channel === DeliveryChannel::Email
                ? $this->accounts->findByEmail($destination->value)
                : $this->accounts->findByMobile($destination->phoneNumber() ?? throw AuthFailure::invalidVerificationToken());

            if ($user === null || ! $user->isActive()) {
                throw AuthFailure::invalidVerificationToken();
            }

            $this->accounts->changePassword($user, $password);
            $this->revocation->revokeEverything($user);

            return $user->id;
        });

        $this->securityEvents->record(SecurityEventType::PasswordReset, $userId);
    }
}
