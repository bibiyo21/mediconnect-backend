<?php

use App\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * /api/v1 platform routes. Module routes are registered by each module's
 * service provider (e.g. app/Modules/Authentication/Routes/api.php).
 */
Route::get('health', [HealthController::class, 'health'])->name('platform.health');
Route::get('ready', [HealthController::class, 'ready'])->name('platform.ready');
