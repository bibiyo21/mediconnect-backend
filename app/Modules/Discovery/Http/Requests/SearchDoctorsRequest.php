<?php

namespace App\Modules\Discovery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /search/doctors (api-specification.md §2.6). Public. HMO and
 * availability filters arrive in Phases 7B and 4. Coordinates are used for
 * this one search only and never stored (D-43).
 */
final class SearchDoctorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Free text: a name, specialty, condition keyword, service or clinic. Up to 100 characters. */
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'specialty' => ['sometimes', 'uuid'],
            'subspecialty' => ['sometimes', 'uuid'],
            'city' => ['sometimes', 'string', 'min:2', 'max:100'],
            'province' => ['sometimes', 'string', 'min:2', 'max:100'],
            'clinic_id' => ['sometimes', 'uuid'],
            'consultation_type' => ['sometimes', Rule::in(['ONLINE', 'IN_PERSON'])],
            /** Centavos. */
            'fee_min' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            /** Centavos. */
            'fee_max' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'gender' => ['sometimes', 'string', 'max:20'],
            /** ISO 639 code, for example `fil`. */
            'language' => ['sometimes', 'string', 'max:10'],
            'experience_min' => ['sometimes', 'integer', 'min:0', 'max:70'],
            'verified' => ['sometimes', 'boolean'],
            'lat' => ['required_with:lng', 'numeric', 'between:4,22'],
            'lng' => ['required_with:lat', 'numeric', 'between:116,127'],
            'radius_km' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['relevance', 'distance', 'fee', 'rating'])],
            /** Opaque, from `meta.next_cursor`. */
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
