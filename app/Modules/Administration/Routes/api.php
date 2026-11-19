<?php

use App\Modules\Administration\Http\Controllers\AdminOverviewController;
use App\Modules\Administration\Http\Controllers\AdminSettingsController;
use App\Modules\Administration\Http\Controllers\AdminUsersController;
use Illuminate\Support\Facades\Route;

/*
 * Admin API (api-specification.md §2.15), under /api/v1/admin. Every route needs
 * a full sign-in (MFA for privileged roles) and its permission; sensitive
 * changes also need a fresh step-up and a reason (rbac-permissions.md §7).
 */
Route::prefix('admin')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied'])->group(function () {
    Route::get('overview', AdminOverviewController::class)->middleware('can:users.view');

    Route::get('users', [AdminUsersController::class, 'index'])->middleware('can:users.view');
    Route::get('users/{userId}', [AdminUsersController::class, 'show'])->whereUuid('userId')->middleware('can:users.view');
    Route::post('users/{userId}/suspend', [AdminUsersController::class, 'suspend'])->whereUuid('userId')->middleware(['can:users.suspend', 'step-up']);
    Route::post('users/{userId}/reactivate', [AdminUsersController::class, 'reactivate'])->whereUuid('userId')->middleware(['can:users.suspend', 'step-up']);
    Route::put('users/{userId}/roles', [AdminUsersController::class, 'updateRoles'])->whereUuid('userId')->middleware(['can:roles.manage', 'step-up']);

    Route::get('settings', [AdminSettingsController::class, 'index'])->middleware('can:settings.manage');
    Route::put('settings/{settingKey}', [AdminSettingsController::class, 'update'])->where('settingKey', '[a-z][a-z0-9_.]{2,99}')->middleware(['can:settings.manage', 'step-up']);
});
