<?php

use App\Modules\Discovery\Http\Controllers\AdminSearchController;
use App\Modules\Discovery\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/* Public search under /api/v1 (api-specification.md §2.6). 60 a minute per IP. */
Route::prefix('search')->middleware('throttle:60,1')->group(function () {
    Route::get('doctors', [SearchController::class, 'doctors']);
    Route::get('clinics', [SearchController::class, 'clinics']);
    Route::get('specialties', [SearchController::class, 'specialties']);
});

/* Search curation (D-42): synonyms, zero-result queue, metrics. */
Route::prefix('admin/search')->middleware(['auth:sanctum', 'signin.valid', 'mfa.satisfied', 'can:search.manage'])->group(function () {
    Route::get('overview', [AdminSearchController::class, 'overview']);
    Route::get('zero-results', [AdminSearchController::class, 'zeroResults']);
    Route::post('zero-results/{zeroResultId}/ignore', [AdminSearchController::class, 'ignore'])->whereUuid('zeroResultId');
    Route::get('synonyms', [AdminSearchController::class, 'synonyms']);
    Route::post('synonyms', [AdminSearchController::class, 'store']);
    Route::patch('synonyms/{synonymId}', [AdminSearchController::class, 'update'])->whereUuid('synonymId');
});
