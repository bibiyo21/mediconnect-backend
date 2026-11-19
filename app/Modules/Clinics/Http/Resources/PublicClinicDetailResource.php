<?php

namespace App\Modules\Clinics\Http\Resources;

use App\Modules\Clinics\Models\Clinic;
use Illuminate\Http\Request;

/**
 * The public clinic page: the directory fields plus up to ten upcoming
 * closures or special hours.
 *
 * @mixin Clinic
 */
final class PublicClinicDetailResource extends PublicClinicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Clinic $c */
        $c = $this->resource;

        return [...parent::toArray($request), 'upcoming_closures' => $this->closures($c)];
    }
}
