<?php

namespace App\Modules\Notifications\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The user's PUSH switches. In-app notices are not listed: they are always on.
 *
 * The keys are literal so the OpenAPI schema is an object with exactly these three booleans (the generated client
 * types them). A new group means a new key here, a new entry in PushPreferenceGroups, and the schema test.
 *
 * @property array<string, bool> $resource
 */
final class PushPreferencesResource extends JsonResource
{
    /** @return array{push: array{walk_in_doctor_checked_out: bool, walk_in_cancelled: bool, walk_in_transferred: bool}} */
    public function toArray($request): array
    {
        return [
            'push' => [
                'walk_in_doctor_checked_out' => (bool) $this->resource['walk_in_doctor_checked_out'],
                'walk_in_cancelled' => (bool) $this->resource['walk_in_cancelled'],
                'walk_in_transferred' => (bool) $this->resource['walk_in_transferred'],
            ],
        ];
    }
}
