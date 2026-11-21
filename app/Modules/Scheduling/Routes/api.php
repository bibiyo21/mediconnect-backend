<?php

use App\Modules\Scheduling\Http\Controllers\ClinicServiceModesController;
use App\Modules\Scheduling\Http\Controllers\MyServiceModesController;
use App\Modules\Scheduling\Http\Controllers\PublicSchedulesController;
use App\Modules\Scheduling\Http\Controllers\ScheduleExceptionsController;
use App\Modules\Scheduling\Http\Controllers\SchedulesController;
use Illuminate\Support\Facades\Route;

/* Scheduling module routes, under /api/v1 (api-specification.md scheduling rows). */

// Public weekly summary (4.3): hours only, ACTIVE doctors only.
Route::get('doctors/{doctorId}/schedules', PublicSchedulesController::class)->whereUuid('doctorId')->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    // Service modes (4.2): the doctor's own, and a clinic's for its locations.
    Route::get('doctors/me/service-modes', [MyServiceModesController::class, 'index']);
    Route::put('doctors/me/services/{serviceId}/mode', [MyServiceModesController::class, 'update'])->whereUuid('serviceId');
    Route::get('clinics/{clinicId}/service-modes', [ClinicServiceModesController::class, 'index'])->whereUuid('clinicId');
    Route::put('clinics/{clinicId}/services/{serviceId}/mode', [ClinicServiceModesController::class, 'update'])->whereUuid(['clinicId', 'serviceId']);

    // Weekly schedules (4.3): the doctor's own or a clinic's; version via If-Match.
    Route::get('schedules', [SchedulesController::class, 'index']);
    Route::post('schedules', [SchedulesController::class, 'store']);
    Route::get('schedules/{scheduleId}', [SchedulesController::class, 'show'])->whereUuid('scheduleId');
    Route::patch('schedules/{scheduleId}', [SchedulesController::class, 'update'])->whereUuid('scheduleId');
    Route::delete('schedules/{scheduleId}', [SchedulesController::class, 'destroy'])->whereUuid('scheduleId');

    // Exceptions (4.4): leave, closures, unavailability and special hours (D-11).
    Route::get('schedule-exceptions', [ScheduleExceptionsController::class, 'index']);
    Route::post('schedule-exceptions', [ScheduleExceptionsController::class, 'store']);
    Route::delete('schedule-exceptions/{exceptionId}', [ScheduleExceptionsController::class, 'destroy'])->whereUuid('exceptionId');
});
