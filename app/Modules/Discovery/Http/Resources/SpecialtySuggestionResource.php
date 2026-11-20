<?php

namespace App\Modules\Discovery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A specialty or subspecialty suggestion for autocomplete.
 *
 * @property array<string, mixed> $resource
 */
final class SpecialtySuggestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string the parent specialty, for filtering doctor search */
            'specialty_id' => $r['specialty_id'],
            /** @var string */
            'id' => $r['id'],
            /** @var string */
            'name' => $r['name'],
            /** @var 'SPECIALTY'|'SUBSPECIALTY' */
            'kind' => $r['kind'],
            /** @var string|null */
            'parent_name' => $r['parent_name'],
            /** @var int ACTIVE doctors with this confirmed specialty */
            'doctor_count' => $r['doctor_count'],
        ];
    }
}
