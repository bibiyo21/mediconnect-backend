<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Http\Requests\MfaCodeRequest;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Services\MfaService;
use App\Modules\Authentication\Services\SessionIssuer;
use App\Modules\Authentication\Services\StepUp;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /auth/mfa/totp/setup, /auth/mfa/totp/confirm, /auth/mfa/step-up.
 */
final class MfaController
{
    public function __construct(private readonly MfaService $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->mfa->startEnrollment($user), message: 'Add this account to your authenticator app, then confirm with a code.');
    }

    /**
     * Confirms enrollment and upgrades the current sign-in to full access.
     * Recovery codes are returned once and never again.
     */
    public function confirm(MfaCodeRequest $request, SessionIssuer $issuer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $recoveryCodes = $this->mfa->confirmEnrollment($user, (string) $request->input('code'));

        $data = ['recovery_codes' => $recoveryCodes];
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->device) {
            $data += $issuer->rotateTokens($token->device, $user, (string) Str::uuid7(), fullAccess: true)->toArray();
        } elseif ($request->hasSession()) {
            $request->session()->put('auth.mfa_satisfied', true);
            $request->session()->regenerate();
        }

        return ApiResponse::success($data, message: 'Multi-factor authentication is on. Store the recovery codes somewhere safe.');
    }

    public function stepUp(MfaCodeRequest $request, StepUp $stepUp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mfa->verify($user, (string) $request->input('code'), null)) {
            throw AuthFailure::invalidCode();
        }

        return ApiResponse::success(['step_up_expires_at' => $stepUp->record($request, $user)]);
    }
}
