<?php

namespace App\Modules\Specialties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /admin/specialties/{id}: only the fields sent change. */
final class UpdateSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:specialties.manage` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120', Rule::unique('specialties', 'name')->ignore($this->route('specialtyId'))],
            'synonyms' => ['sometimes', 'array', 'max:20'],
            'synonyms.*' => ['string', 'min:2', 'max:60', 'distinct:ignore_case'],
            'is_active' => ['sometimes', 'boolean'],
            /** A MEDICAL_ADMIN marks the entry as reviewed (D-39). */
            'reviewed' => ['sometimes', 'boolean'],
        ];
    }
}
