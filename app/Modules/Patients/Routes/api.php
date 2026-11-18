<?php

use App\Modules\Patients\Http\Controllers\DependentsController;
use App\Modules\Patients\Http\Controllers\MyPatientProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Patients module routes (api-specification.md §2.2), under /api/v1.
 */
Route::prefix('patients')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::get('me', [MyPatientProfileController::class, 'show']);
    Route::patch('me', [MyPatientProfileController::class, 'update']);

    Route::get('me/dependents', [DependentsController::class, 'index']);
    Route::post('me/dependents', [DependentsController::class, 'store'])->middleware('idempotent');
    Route::get('me/dependents/{dependentId}', [DependentsController::class, 'show'])->whereUuid('dependentId');
    Route::patch('me/dependents/{dependentId}', [DependentsController::class, 'update'])->whereUuid('dependentId');
    Route::delete('me/dependents/{dependentId}', [DependentsController::class, 'destroy'])->whereUuid('dependentId');
});
