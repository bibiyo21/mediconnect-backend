<?php

namespace App\Modules\Discovery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /search/clinics. Public; coordinates are used for this search only (D-43). */
final class SearchClinicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'string', 'min:2', 'max:100'],
            'lat' => ['required_with:lng', 'numeric', 'between:4,22'],
            'lng' => ['required_with:lat', 'numeric', 'between:116,127'],
            'radius_km' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
