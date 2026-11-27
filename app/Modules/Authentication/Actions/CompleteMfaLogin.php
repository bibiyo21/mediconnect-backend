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
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CompleteMfaLogin
{
    public function __construct(
        private readonly PendingMfaLogins $pending,
        private readonly MfaService $mfa,
        private readonly SessionIssuer $issuer,
        private readonly Accounts $accounts,
        private readonly RecordsSecurityEvents $securityEvents,
    ) {}

    public function handle(Request $request, string $challengeId, ?string $code, ?string $recoveryCode): IssuedSession
    {
        $pending = $this->pending->find($challengeId);
        $user = $pending ? User::find($pending['user_id']) : null;

        if ($pending === null || $user === null || ! $user->isActive()) {
            throw AuthFailure::invalidCode();
        }

        // The first factor decided cookie or token. A cookie sign-in needs a session, which only a stateful request has:
        // finishing it from another kind of client is a client error (422), never a 500.
        $web = $pending['web'] ?? null;
        if ($web === true && ! $this->issuer->isWebClient($request)) {
            throw ValidationException::withMessages(['mfa_challenge_id' => 'Finish this sign-in from the same web client that started it.']);
        }

        if (! $this->mfa->verify($user, $code, $recoveryCode)) {
            $this->pending->fail($challengeId);

            throw AuthFailure::invalidCode();
        }

        $this->pending->finish($challengeId);

        $session = $this->issuer->signIn($request, $user, $pending['device'], fullAccess: true, web: $web);
        $this->accounts->recordLogin($user);
        $this->securityEvents->record(SecurityEventType::LoginSucceeded, $user->id, ['device_id' => $session->device->id, 'mfa' => true]);

        return $session;
    }
}
