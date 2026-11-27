<?php

use App\Modules\Consultations\Http\Controllers\ConsultationLifecycleController;
use App\Modules\Consultations\Http\Controllers\ConsultationReadController;
use Illuminate\Support\Facades\Route;

/* Consultation module routes, under /api/v1 (api-specification.md §2.10). Named limiters, per the Phase 5 plan. */

Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    // Reads: named limiter, UUID-constrained. A global reader's read is audited inside the reader, not here.
    Route::get('consultations', [ConsultationReadController::class, 'index'])->middleware('throttle:consultation-read');
    Route::get('consultations/{id}', [ConsultationReadController::class, 'show'])->whereUuid('id')->middleware('throttle:consultation-read');
    // Writes: the limiter runs before the Idempotency-Key middleware, so a 429 is never stored and replayed. UUID-constrained.
    Route::post('consultations/{id}/start', [ConsultationLifecycleController::class, 'start'])->whereUuid('id')->middleware(['throttle:consultation-write', 'idempotent']);
    Route::post('consultations/{id}/end', [ConsultationLifecycleController::class, 'end'])->whereUuid('id')->middleware(['throttle:consultation-write', 'idempotent']);
    Route::post('consultations/{id}/interrupt', [ConsultationLifecycleController::class, 'interrupt'])->whereUuid('id')->middleware(['throttle:consultation-write', 'idempotent']);
    Route::post('consultations/{id}/resume', [ConsultationLifecycleController::class, 'resume'])->whereUuid('id')->middleware(['throttle:consultation-write', 'idempotent']);
    Route::post('walk-ins/{id}/complete', [ConsultationLifecycleController::class, 'complete'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
});
