<?php

use App\Modules\Doctors\Http\Controllers\Admin\AdminDoctorsController;
use App\Modules\Doctors\Http\Controllers\CredentialFileController;
use App\Modules\Doctors\Http\Controllers\MyCredentialsController;
use App\Modules\Doctors\Http\Controllers\MyDoctorProfileController;
use App\Modules\Doctors\Http\Controllers\MyServicesController;
use App\Modules\Doctors\Http\Controllers\SubmitForReviewController;
use Illuminate\Support\Facades\Route;

/* Doctors module routes, under /api/v1 (api-specification.md §2.3). */
Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::post('doctors', [MyDoctorProfileController::class, 'store'])->middleware('throttle:auth-strict');
    Route::get('doctors/me', [MyDoctorProfileController::class, 'show']);
    Route::patch('doctors/me', [MyDoctorProfileController::class, 'update']);
    Route::post('doctors/me/submit-for-review', SubmitForReviewController::class);

    Route::get('doctors/me/services', [MyServicesController::class, 'index']);
    Route::post('doctors/me/services', [MyServicesController::class, 'store']);
    Route::patch('doctors/me/services/{serviceId}', [MyServicesController::class, 'update'])->whereUuid('serviceId');
    Route::delete('doctors/me/services/{serviceId}', [MyServicesController::class, 'destroy'])->whereUuid('serviceId');

    Route::get('doctors/me/credentials', [MyCredentialsController::class, 'index']);
    Route::post('doctors/me/credentials', [MyCredentialsController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('doctors/me/credentials/{credentialId}', [MyCredentialsController::class, 'destroy'])->whereUuid('credentialId');
    Route::get('doctors/me/credentials/{credentialId}/download-url', [MyCredentialsController::class, 'downloadUrl'])->whereUuid('credentialId');
});

/*
 * Verification for reviewers (2.9). Decisions need a fresh step-up; every
 * non-GET request under /admin writes admin_activity_logs.
 */
Route::prefix('admin/doctors')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied', 'can:doctors.verify'])->group(function () {
    Route::get('/', [AdminDoctorsController::class, 'index']);
    Route::get('{doctorId}', [AdminDoctorsController::class, 'show'])->whereUuid('doctorId');
    Route::post('{doctorId}/start-review', [AdminDoctorsController::class, 'startReview'])->whereUuid('doctorId');
    Route::get('{doctorId}/credentials/{credentialId}/download-url', [AdminDoctorsController::class, 'credentialDownloadUrl'])->whereUuid(['doctorId', 'credentialId']);
    Route::post('{doctorId}/credentials/{credentialId}/review', [AdminDoctorsController::class, 'reviewCredential'])->whereUuid(['doctorId', 'credentialId']);
    Route::post('{doctorId}/specialties/confirm', [AdminDoctorsController::class, 'confirmSpecialties'])->whereUuid('doctorId');
    Route::post('{doctorId}/verify', [AdminDoctorsController::class, 'verify'])->whereUuid('doctorId')->middleware('step-up');
    Route::post('{doctorId}/activate', [AdminDoctorsController::class, 'activate'])->whereUuid('doctorId')->middleware('step-up');
    Route::post('{doctorId}/reject', [AdminDoctorsController::class, 'reject'])->whereUuid('doctorId')->middleware(['can:doctors.reject', 'step-up']);
});

/*
 * Signed, short-lived download link (no bearer token: browsers and native
 * viewers open it directly). The signature binds the document and its owner.
 */
Route::get('doctors/credentials/{credentialId}/file', CredentialFileController::class)
    ->middleware(['signed', 'throttle:downloads'])->whereUuid('credentialId')->name('doctor-credentials.file');
