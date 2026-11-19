<?php

use App\Modules\Clinics\Http\Controllers\AdminClinicsController;
use App\Modules\Clinics\Http\Controllers\ClinicHolidaysController;
use App\Modules\Clinics\Http\Controllers\ClinicLocationsController;
use App\Modules\Clinics\Http\Controllers\ClinicManagementController;
use App\Modules\Clinics\Http\Controllers\ClinicMembersController;
use Illuminate\Support\Facades\Route;

/*
 * Clinics module routes, under /api/v1 (api-specification.md §2.4). Management
 * routes are clinic-scoped (ClinicGuard): the public directory arrives in 2.11.
 */
Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::get('admin/clinics', [AdminClinicsController::class, 'index'])->middleware('can:clinics.create');
    Route::post('admin/clinics', [AdminClinicsController::class, 'store'])->middleware('can:clinics.create');

    Route::get('me/clinics', [ClinicManagementController::class, 'mine']);

    Route::prefix('clinics/{clinicId}')->whereUuid('clinicId')->group(function () {
        Route::get('management', [ClinicManagementController::class, 'show']);
        Route::patch('/', [ClinicManagementController::class, 'update']);

        Route::post('locations', [ClinicLocationsController::class, 'store']);
        Route::patch('locations/{locationId}', [ClinicLocationsController::class, 'update'])->whereUuid('locationId');
        Route::put('locations/{locationId}/operating-hours', [ClinicLocationsController::class, 'replaceHours'])->whereUuid('locationId');
        Route::post('locations/{locationId}/services', [ClinicLocationsController::class, 'storeService'])->whereUuid('locationId');
        Route::patch('locations/{locationId}/services/{serviceId}', [ClinicLocationsController::class, 'updateService'])->whereUuid(['locationId', 'serviceId']);

        Route::post('holidays', [ClinicHolidaysController::class, 'store']);
        Route::delete('holidays/{holidayId}', [ClinicHolidaysController::class, 'destroy'])->whereUuid('holidayId');

        Route::post('members', [ClinicMembersController::class, 'store']);
        Route::delete('members/{memberId}', [ClinicMembersController::class, 'destroy'])->whereUuid('memberId');
    });
});
