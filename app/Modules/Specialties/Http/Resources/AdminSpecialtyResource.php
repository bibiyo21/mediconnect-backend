<?php

namespace App\Modules\Specialties\Http\Resources;

use App\Modules\Specialties\Models\Specialty;
use App\Modules\Specialties\Models\Subspecialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A specialty with all its subspecialties, including inactive ones (admin view).
 *
 * @mixin Specialty
 */
final class AdminSpecialtyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Specialty $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'synonyms' => $s->synonyms,
            'is_active' => $s->is_active,
            /** @var 'PENDING_REVIEW'|'REVIEWED' */
            'review_status' => $s->review_status,
            'subspecialties' => $s->subspecialties->map(fn (Subspecialty $sub) => [
                'id' => $sub->id, 'name' => $sub->name, 'slug' => $sub->slug, 'is_active' => $sub->is_active,
            ])->values()->all(),
        ];
    }
}
