<?php

namespace App\Modules\Authentication\Actions;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Services\IssuedSession;
use App\Modules\Authentication\Services\MfaService;
use App\Modules\Authentication\Services\PendingMfaLogins;
use App\Modules\Authentication\Services\SessionIssuer;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\InvalidValue;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * First factor. Unknown accounts and wrong passwords get the same response,
 * and a password hash check runs either way so timing does not tell them apart.
 */
final class AttemptLogin
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly MfaService $mfa,
        private readonly PendingMfaLogins $pending,
        private readonly SessionIssuer $issuer,
        private readonly RecordsSecurityEvents $securityEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $deviceInfo
     * @return IssuedSession|array{id: string, expires_at: string} a session, or a pending MFA challenge
     */
    public function handle(Request $request, string $identifier, string $password, array $deviceInfo): IssuedSession|array
    {
        $user = $this->findUser($identifier);

        $passwordOk = Hash::check($password, $user !== null ? $user->password : (self::$dummyHash ??= Hash::make('dummy-password-for-timing')));

        if ($user === null || ! $passwordOk) {
            $this->securityEvents->record(SecurityEventType::LoginFailed, $user?->id);

            throw AuthFailure::invalidCredentials();
        }

        if (! $user->isActive()) {
            $this->securityEvents->record(SecurityEventType::SuspendedAccountLogin, $user->id);

            throw AuthFailure::accountSuspended();
        }

        if ($this->mfa->isEnrolled($user)) {
            return $this->pending->start($user->id, $deviceInfo);
        }

        $session = $this->issuer->signIn($request, $user, $deviceInfo, $this->mfa->accessIsFull($user, mfaJustVerified: false));
        $this->accounts->recordLogin($user);
        $this->securityEvents->record(SecurityEventType::LoginSucceeded, $user->id, ['device_id' => $session->device->id]);

        return $session;
    }

    private function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return $this->accounts->findByEmail(mb_strtolower($identifier));
        }

        try {
            return $this->accounts->findByMobile(PhoneNumber::parse($identifier));
        } catch (InvalidValue) {
            return null;
        }
    }
}
