<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /doctors: the public directory. Simple, indexed filters only; free-text
 * search is Phase 3 (`/search/doctors`). Unknown filters are rejected.
 */
final class ListPublicDoctorsRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:specialty_id,city,consultation_type,clinic_id'],
            'filter.specialty_id' => ['sometimes', 'uuid'],
            /** Exact city name, case-insensitive, for example "Makati". */
            'filter.city' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter.consultation_type' => ['sometimes', Rule::in(['ONLINE', 'IN_PERSON'])],
            'filter.clinic_id' => ['sometimes', 'uuid'],
        ];
    }
}
