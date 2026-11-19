<?php

namespace App\Modules\Specialties\Http\Controllers;

use App\Modules\Specialties\Services\SpecialtyCatalog;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/** GET /specialties: public, active specialties and subspecialties only (cached). */
final class SpecialtiesController
{
    public function __invoke(SpecialtyCatalog $catalog): JsonResponse
    {
        return ApiResponse::success($catalog->active());
    }
}
