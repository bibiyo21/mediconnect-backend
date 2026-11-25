<?php

namespace App\Modules\WalkIns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /walk-ins/search: the staff dedupe search over one location's live entries today. */
final class SearchWalkInsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinic_location_id' => ['required', 'uuid'],
            /** A queue number (`A-07`, `7`), an exact phone number, or part of a name (at least 2 characters). Never logged or audited. */
            'term' => ['required', 'string', 'min:1', 'max:120'],
        ];
    }
}
