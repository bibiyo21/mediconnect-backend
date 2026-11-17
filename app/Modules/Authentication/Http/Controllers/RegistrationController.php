<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Authentication\Actions\RegisterAccount;
use App\Modules\Authentication\Http\Requests\DeviceRules;
use App\Modules\Authentication\Http\Requests\RegisterRequest;
use App\Modules\Authentication\Http\Resources\AccountResource;
use App\Modules\Authentication\Services\MfaService;
use App\Modules\Authentication\Services\SessionIssuer;
use App\Modules\Users\Contracts\Accounts;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /auth/register (step 2; step 1 is POST /auth/otp with purpose REGISTER). */
final class RegistrationController
{
    public function __invoke(RegisterRequest $request, RegisterAccount $register, SessionIssuer $issuer, MfaService $mfa, Accounts $accounts): JsonResponse
    {
        $user = $register->handle(
            $request->destination(),
            (string) $request->input('verification_token'),
            (string) $request->input('password'),
            $request->identity(),
        );

        $session = $issuer->signIn($request, $user, DeviceRules::from($request), $mfa->accessIsFull($user, mfaJustVerified: false));
        $accounts->recordLogin($user);

        return ApiResponse::success(
            ['user' => (new AccountResource($user->load('profile')))->resolve($request), ...$session->toArray()],
            status: 201,
        );
    }
}
