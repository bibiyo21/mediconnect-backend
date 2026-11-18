<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Http\Requests\AccountDeletionRequest;
use App\Modules\Authentication\Services\DeviceRevocation;
use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Enums\DeliveryChannel;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * POST /auth/account/deletion-request (D-38, App Store guideline 5.1.1(v)).
 *
 * Signs the account out everywhere at once and starts the grace period.
 * Signing in again before it ends cancels the request (Accounts::recordLogin);
 * after it, AnonymizeDeletedAccounts replaces the personal data.
 */
final class AccountDeletionController
{
    public function __invoke(AccountDeletionRequest $request, Accounts $accounts, DeviceRevocation $revocation, RecordsSecurityEvents $events, SendsOneTimeMessages $messages): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The password is incorrect.']]);
        }

        // Staff and clinician accounts carry operational responsibilities; an administrator closes them.
        if ($user->getRoleNames()->contains(fn (string $role) => $role !== Role::Patient->value)) {
            throw ValidationException::withMessages(['account' => ['This account has staff or clinician roles. Ask an administrator to close it.']]);
        }

        $accounts->requestDeletion($user);
        $user->refresh();
        $deleteOn = ($user->deletion_requested_at ?? now()->toImmutable())->addDays((int) config('mediconnect.account_deletion_grace_days'));

        $revocation->revokeEverything($user);
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $events->record(SecurityEventType::AccountDeletionRequested, $user->id);
        $date = $deleteOn->setTimezone((string) config('mediconnect.timezone'))->format('j F Y');
        $notice = "Your MediConnect account is scheduled for deletion on {$date}. You were signed out on every device. To keep your account, sign in before then.";
        if ($user->email !== null) {
            $messages->sendNotice(DeliveryChannel::Email, $user->email, $notice);
        }
        if ($user->mobile_e164 !== null) {
            $messages->sendNotice(DeliveryChannel::Sms, $user->mobile_e164, $notice);
        }

        return ApiResponse::success(
            ['deletion_scheduled_for' => $deleteOn->toIso8601ZuluString()],
            message: 'Your account is scheduled for deletion. Sign in before then to keep it.',
            status: 202,
        );
    }
}
