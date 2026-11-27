<?php

use App\Modules\WalkIns\Http\Controllers\QueueControlController;
use App\Modules\WalkIns\Http\Controllers\QueueOperationsController;
use App\Modules\WalkIns\Http\Controllers\WalkInsController;
use Illuminate\Support\Facades\Route;

/* Walk-in module routes, under /api/v1 (api-specification.md §2.9). Named limiters, per the Phase 5 plan. */

Route::middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->prefix('walk-ins')->group(function () {
    // The limiter runs before the Idempotency-Key middleware, so a 429 is never stored and replayed. Registration: Idempotency-Key required; 10 a minute per patient, 60 for staff and doctors.
    Route::post('/', [WalkInsController::class, 'store'])->middleware(['throttle:walkin-register', 'idempotent']);

    // Reads. Fixed paths first, so `search`, `queue` and `join-preview` are never taken for an entry id.
    Route::get('queue', [WalkInsController::class, 'queue'])->middleware('throttle:walkin-read');
    Route::get('join-preview', [WalkInsController::class, 'joinPreview'])->middleware('throttle:walkin-read');
    // A search is a POST with a JSON body, never a GET: a phone number or a name must not travel in a URL (access logs, proxies, Sentry). It changes nothing, so it takes no Idempotency-Key.
    Route::post('search', [WalkInsController::class, 'search'])->middleware('throttle:walkin-search');
    Route::get('{id}', [WalkInsController::class, 'show'])->whereUuid('id')->middleware('throttle:walkin-read');

    // Moves on an entry: Idempotency-Key required.
    Route::post('{id}/arrive', [WalkInsController::class, 'arrive'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/cancel', [WalkInsController::class, 'cancel'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);

    // Queue operations (5.6-2): the limiter runs before the Idempotency-Key middleware, so a 429 is never stored and replayed. UUID-constrained; the permission and the queue's state decide.
    Route::post('queues/{id}/call-next', [QueueOperationsController::class, 'callNext'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/call', [QueueOperationsController::class, 'call'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/recall', [QueueOperationsController::class, 'recall'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/skip', [QueueOperationsController::class, 'skip'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/no-show', [QueueOperationsController::class, 'noShow'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/return', [QueueOperationsController::class, 'returnToQueue'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);
    Route::post('{id}/priority', [QueueOperationsController::class, 'priority'])->whereUuid('id')->middleware(['throttle:walkin-write', 'idempotent']);

    // Pause and reopen a queue: guarded by state (a repeat is 409 INVALID_STATE_TRANSITION).
    Route::post('queues/{id}/pause', [QueueControlController::class, 'pause'])->whereUuid('id')->middleware('throttle:walkin-write');
    Route::post('queues/{id}/open', [QueueControlController::class, 'open'])->whereUuid('id')->middleware('throttle:walkin-write');
});
