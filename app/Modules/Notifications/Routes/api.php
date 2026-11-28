<?php

use App\Modules\Notifications\Http\Controllers\BroadcastAuthController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferencesController;
use Illuminate\Support\Facades\Route;

/* Notifications and real-time (architecture.md §15.2), under /api/v1. */

// An IP limit before authentication, the standard protected stack, then a named per-user limiter:
// a client joins several channels on connect.
Route::post('broadcasting/auth', BroadcastAuthController::class)
    ->middleware(['throttle:120,1', 'auth:sanctum', 'signin.valid', 'mfa.satisfied', 'throttle:broadcast-auth']);

// The signed-in user's own PUSH switches (HAG-38). No id in the path: a user only ever reads or writes their own.
Route::prefix('notifications')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied', 'throttle:notification-preferences'])->group(function () {
    Route::get('preferences', [NotificationPreferencesController::class, 'index']);
    Route::put('preferences', [NotificationPreferencesController::class, 'update']);
});
