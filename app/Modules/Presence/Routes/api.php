<?php

use App\Modules\Presence\Http\Controllers\PresenceController;
use App\Modules\Presence\Http\Controllers\PublicPresenceController;
use Illuminate\Support\Facades\Route;

/* Presence module routes, under /api/v1 (api-specification.md §2.7). Named limiters, per the Phase 5 plan. */

// Public status: whitelisted fields only (Q13), cached 10 s server-side, 60 a minute per IP. Production exposure needs owner sign-off.
Route::get('presence/doctors/{doctorId}', [PublicPresenceController::class, 'show'])->whereUuid('doctorId')->middleware('throttle:presence-public');

Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::get('presence/me', [PresenceController::class, 'me'])->middleware('throttle:presence-read');
    Route::get('presence/me/day', [PresenceController::class, 'day'])->middleware('throttle:presence-read');
    Route::get('presence/check-out/preview', [PresenceController::class, 'checkOutPreview'])->middleware('throttle:presence-read');

    // Writes: 30 a minute per user. Guarded by the open-session index and the transition table, not by an Idempotency-Key.
    Route::middleware('throttle:presence-write')->group(function () {
        Route::post('presence/check-in', [PresenceController::class, 'checkIn']);
        Route::post('presence/break/start', [PresenceController::class, 'startBreak']);
        Route::post('presence/break/end', [PresenceController::class, 'endBreak']);
        Route::post('presence/unavailable', [PresenceController::class, 'markUnavailable']);
        Route::post('presence/available', [PresenceController::class, 'markAvailable']);
        Route::post('presence/check-out', [PresenceController::class, 'checkOut']);
    });
});
