<?php

use App\Modules\Patients\Http\Controllers\MyPatientProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Patients module routes (api-specification.md §2.2), under /api/v1.
 */
Route::prefix('patients')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::get('me', [MyPatientProfileController::class, 'show']);
    Route::patch('me', [MyPatientProfileController::class, 'update']);
});
