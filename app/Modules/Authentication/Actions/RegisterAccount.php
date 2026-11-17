<?php

namespace App\Modules\Authentication\Actions;

use App\Modules\Audit\Contracts\RecordsConsents;
use App\Modules\Audit\Enums\ConsentType;
use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Services\Destination;
use App\Modules\Authentication\Services\OtpService;
use App\Modules\Patients\Contracts\PatientProfiles;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Enums\DeliveryChannel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of registration. Step 1 (POST /auth/otp, purpose REGISTER) proved
 * control of the email or mobile number without revealing whether an account
 * already uses it; only a destination with no account ever received a code.
 */
final class RegisterAccount
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Accounts $accounts,
        private readonly PatientProfiles $patients,
        private readonly AssignsRoles $roles,
        private readonly RecordsConsents $consents,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, middle_name?: string|null, date_of_birth?: string|null, sex?: string|null}  $identity
     */
    public function handle(Destination $destination, string $verificationToken, string $password, array $identity): User
    {
        try {
            return DB::transaction(function () use ($destination, $verificationToken, $password, $identity) {
                $this->otp->consume($verificationToken, OtpPurpose::Register, $destination);

                $email = $destination->channel === DeliveryChannel::Email ? $destination->value : null;
                $user = $this->accounts->register($email, $destination->phoneNumber(), $password, $identity);

                $this->patients->createForAccount($user, $identity);
                $this->roles->assign($user, Role::Patient);

                $this->consents->record($user->id, ConsentType::PrivacyNotice, (string) config('authentication.legal.privacy_notice_version'), true);
                $this->consents->record($user->id, ConsentType::Terms, (string) config('authentication.legal.terms_version'), true);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Another registration for the same contact won the race.
            throw AuthFailure::invalidVerificationToken();
        }
    }
}
