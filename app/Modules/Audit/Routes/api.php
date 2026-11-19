<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

/* Audit module routes, under /api/v1 (api-specification.md §2.15). */
Route::prefix('admin')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied', 'can:audit_logs.view'])->group(function () {
    Route::get('audit-logs', AuditLogController::class);
});
