<?php

use App\Modules\Authentication\Http\Controllers\AccountDeletionController;
use App\Modules\Authentication\Http\Controllers\ContactChangeController;
use App\Modules\Authentication\Http\Controllers\ContactVerificationController;
use App\Modules\Authentication\Http\Controllers\DeviceController;
use App\Modules\Authentication\Http\Controllers\MfaController;
use App\Modules\Authentication\Http\Controllers\OtpController;
use App\Modules\Authentication\Http\Controllers\PasswordResetController;
use App\Modules\Authentication\Http\Controllers\RegistrationController;
use App\Modules\Authentication\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

/*
 * /api/v1/auth — api-specification.md §2.1. Paths from CLAUDE.md §23.1 are kept
 * verbatim; the others are proposed additions listed there.
 */
Route::prefix('auth')->group(function () {
    // Public. Strict limits per identifier and per IP (api-specification.md §1.7).
    Route::middleware('throttle:auth-strict')->group(function () {
        Route::post('register', RegistrationController::class);
        Route::post('login', [SessionController::class, 'login']);
        Route::post('otp', [OtpController::class, 'request']);
        Route::post('forgot-password', [OtpController::class, 'forgotPassword']);
        Route::post('reset-password', PasswordResetController::class);
        Route::post('mfa/verify', [SessionController::class, 'completeMfa']);
    });

    Route::post('otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify');
    Route::post('refresh', [SessionController::class, 'refresh'])->middleware('throttle:auth-refresh');

    // Signed in, even if MFA enrollment is still owed.
    Route::middleware(['auth:sanctum', 'signin.valid'])->group(function () {
        Route::post('logout', [SessionController::class, 'logout']);
        Route::post('mfa/totp/setup', [MfaController::class, 'setup']);
        Route::post('mfa/totp/confirm', [MfaController::class, 'confirm']);

        // Fully signed in.
        Route::middleware('mfa.satisfied')->group(function () {
            Route::get('me', [SessionController::class, 'me']);
            Route::get('devices', [DeviceController::class, 'index']);
            Route::delete('devices/{deviceId}', [DeviceController::class, 'destroy'])->whereUuid('deviceId');
            Route::post('mfa/step-up', [MfaController::class, 'stepUp'])->middleware('throttle:auth-strict');
            Route::post('verify-email', [ContactVerificationController::class, 'email']);
            Route::post('verify-mobile', [ContactVerificationController::class, 'mobile']);
            Route::post('contact/otp', [ContactChangeController::class, 'requestCode'])->middleware('throttle:auth-strict');
            Route::put('contact', [ContactChangeController::class, 'confirm'])->middleware('throttle:auth-strict');
            Route::post('account/deletion-request', AccountDeletionController::class)->middleware('throttle:auth-strict');
        });
    });
});
