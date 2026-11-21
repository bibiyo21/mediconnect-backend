<?php

use App\Modules\Scheduling\Http\Controllers\ClinicServiceModesController;
use App\Modules\Scheduling\Http\Controllers\MyServiceModesController;
use Illuminate\Support\Facades\Route;

/* Scheduling module routes, under /api/v1 (api-specification.md scheduling rows). */
Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    // Service modes (4.2): the doctor's own, and a clinic's for its locations.
    Route::get('doctors/me/service-modes', [MyServiceModesController::class, 'index']);
    Route::put('doctors/me/services/{serviceId}/mode', [MyServiceModesController::class, 'update'])->whereUuid('serviceId');
    Route::get('clinics/{clinicId}/service-modes', [ClinicServiceModesController::class, 'index'])->whereUuid('clinicId');
    Route::put('clinics/{clinicId}/services/{serviceId}/mode', [ClinicServiceModesController::class, 'update'])->whereUuid(['clinicId', 'serviceId']);
});
