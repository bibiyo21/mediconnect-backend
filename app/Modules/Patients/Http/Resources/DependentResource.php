<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Dependent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dependent as their guardian sees them. `id` is the dependent's patient
 * profile id: the identifier used later to book care for them.
 *
 * @mixin Dependent
 */
final class DependentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Dependent $link */
        $link = $this->resource;
        $person = $link->dependent;

        return [
            'id' => $person->id,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'last_name' => $person->last_name,
            'date_of_birth' => $person->date_of_birth?->toDateString(),
            'sex' => $person->sex?->value,
            'relationship' => $link->relationship->value,
            'authorized_at' => $link->authorized_at?->toIso8601ZuluString(),
        ];
    }
}
