<?php

use App\Modules\Clinics\Http\Controllers\AdminClinicsController;
use App\Modules\Clinics\Http\Controllers\ClinicDoctorAssignmentsController;
use App\Modules\Clinics\Http\Controllers\ClinicHolidaysController;
use App\Modules\Clinics\Http\Controllers\ClinicLocationsController;
use App\Modules\Clinics\Http\Controllers\ClinicManagementController;
use App\Modules\Clinics\Http\Controllers\ClinicMembersController;
use App\Modules\Clinics\Http\Controllers\MyAssignmentsController;
use App\Modules\Clinics\Http\Controllers\PublicClinicsController;
use Illuminate\Support\Facades\Route;

/*
 * Clinics module routes, under /api/v1 (api-specification.md §2.4). Management
 * routes are clinic-scoped (ClinicGuard): the public directory arrives in 2.11.
 */
// Public directory (2.11): ACTIVE clinics and locations only; no staff or member data.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('clinics', [PublicClinicsController::class, 'index']);
    Route::get('clinics/{clinicId}', [PublicClinicsController::class, 'show'])->whereUuid('clinicId');
    Route::get('clinics/{clinicId}/doctors', [PublicClinicsController::class, 'doctors'])->whereUuid('clinicId');
    Route::get('clinics/{clinicId}/operating-hours', [PublicClinicsController::class, 'operatingHours'])->whereUuid('clinicId');
});

Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::get('admin/clinics', [AdminClinicsController::class, 'index'])->middleware('can:clinics.create');
    Route::post('admin/clinics', [AdminClinicsController::class, 'store'])->middleware('can:clinics.create');

    Route::get('me/clinics', [ClinicManagementController::class, 'mine']);

    // The doctor's side of clinic assignments (2.10).
    Route::get('doctors/me/assignments', [MyAssignmentsController::class, 'index']);
    Route::post('doctors/me/assignments/{assignmentId}/accept', [MyAssignmentsController::class, 'accept'])->whereUuid('assignmentId');
    Route::post('doctors/me/assignments/{assignmentId}/end', [MyAssignmentsController::class, 'end'])->whereUuid('assignmentId');

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

        Route::get('doctor-assignments', [ClinicDoctorAssignmentsController::class, 'index']);
        Route::post('doctors', [ClinicDoctorAssignmentsController::class, 'store']);
        Route::post('doctor-assignments/{assignmentId}/end', [ClinicDoctorAssignmentsController::class, 'end'])->whereUuid('assignmentId');

        Route::post('members', [ClinicMembersController::class, 'store']);
        Route::delete('members/{memberId}', [ClinicMembersController::class, 'destroy'])->whereUuid('memberId');
    });
});
