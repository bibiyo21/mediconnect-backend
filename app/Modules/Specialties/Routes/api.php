<?php

use App\Modules\Specialties\Http\Controllers\AdminSpecialtiesController;
use App\Modules\Specialties\Http\Controllers\SpecialtiesController;
use Illuminate\Support\Facades\Route;

/* Specialties module routes, under /api/v1. */
Route::get('specialties', SpecialtiesController::class)->middleware('throttle:60,1');

Route::prefix('admin')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied', 'can:specialties.manage'])->group(function () {
    Route::get('specialties', [AdminSpecialtiesController::class, 'index']);
    Route::post('specialties', [AdminSpecialtiesController::class, 'store']);
    Route::patch('specialties/{specialtyId}', [AdminSpecialtiesController::class, 'update'])->whereUuid('specialtyId');
    Route::post('specialties/{specialtyId}/subspecialties', [AdminSpecialtiesController::class, 'storeSubspecialty'])->whereUuid('specialtyId');
    Route::patch('subspecialties/{subspecialtyId}', [AdminSpecialtiesController::class, 'updateSubspecialty'])->whereUuid('subspecialtyId');
});
