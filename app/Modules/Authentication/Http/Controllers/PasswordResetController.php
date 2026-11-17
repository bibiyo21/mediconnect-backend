<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Authentication\Actions\ResetPassword;
use App\Modules\Authentication\Http\Requests\ResetPasswordRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/** POST /auth/reset-password (POST /auth/forgot-password is in OtpController). */
final class PasswordResetController
{
    public function __invoke(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->handle($request->destination(), (string) $request->input('verification_token'), (string) $request->input('password'));

        return ApiResponse::success(message: 'Your password was changed. Please sign in again on your devices.');
    }
}
