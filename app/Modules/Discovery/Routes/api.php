<?php

use App\Modules\Discovery\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/* Public search under /api/v1 (api-specification.md §2.6). 60 a minute per IP. */
Route::prefix('search')->middleware('throttle:60,1')->group(function () {
    Route::get('doctors', [SearchController::class, 'doctors']);
    Route::get('clinics', [SearchController::class, 'clinics']);
    Route::get('specialties', [SearchController::class, 'specialties']);
});
