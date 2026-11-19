<?php

use App\Modules\Doctors\Http\Controllers\MyDoctorProfileController;
use Illuminate\Support\Facades\Route;

/* Doctors module routes, under /api/v1 (api-specification.md §2.3). */
Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::post('doctors', [MyDoctorProfileController::class, 'store'])->middleware('throttle:auth-strict');
    Route::get('doctors/me', [MyDoctorProfileController::class, 'show']);
    Route::patch('doctors/me', [MyDoctorProfileController::class, 'update']);
});
