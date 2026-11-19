<?php

use App\Modules\Doctors\Http\Controllers\CredentialFileController;
use App\Modules\Doctors\Http\Controllers\MyCredentialsController;
use App\Modules\Doctors\Http\Controllers\MyDoctorProfileController;
use App\Modules\Doctors\Http\Controllers\SubmitForReviewController;
use Illuminate\Support\Facades\Route;

/* Doctors module routes, under /api/v1 (api-specification.md §2.3). */
Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::post('doctors', [MyDoctorProfileController::class, 'store'])->middleware('throttle:auth-strict');
    Route::get('doctors/me', [MyDoctorProfileController::class, 'show']);
    Route::patch('doctors/me', [MyDoctorProfileController::class, 'update']);
    Route::post('doctors/me/submit', SubmitForReviewController::class);

    Route::get('doctors/me/credentials', [MyCredentialsController::class, 'index']);
    Route::post('doctors/me/credentials', [MyCredentialsController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('doctors/me/credentials/{credentialId}', [MyCredentialsController::class, 'destroy'])->whereUuid('credentialId');
    Route::get('doctors/me/credentials/{credentialId}/download-url', [MyCredentialsController::class, 'downloadUrl'])->whereUuid('credentialId');
});

/*
 * Signed, short-lived download link (no bearer token: browsers and native
 * viewers open it directly). The signature binds the document and its owner.
 */
Route::get('doctors/credentials/{credentialId}/file', CredentialFileController::class)
    ->middleware(['signed', 'throttle:downloads'])->whereUuid('credentialId')->name('doctor-credentials.file');
