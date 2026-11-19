<?php

namespace App\Modules\Clinics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /clinics: the public clinic directory. Search is Phase 3. */
final class ListPublicClinicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'filter' => ['sometimes', 'array:city'],
            /** Exact city name, case-insensitive. */
            'filter.city' => ['sometimes', 'string', 'min:2', 'max:100'],
        ];
    }
}
