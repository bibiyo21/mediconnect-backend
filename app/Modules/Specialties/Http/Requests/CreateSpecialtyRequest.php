<?php

namespace App\Modules\Specialties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /admin/specialties. */
final class CreateSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:specialties.manage` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('specialties', 'name')],
            'synonyms' => ['sometimes', 'array', 'max:20'],
            'synonyms.*' => ['string', 'min:2', 'max:60', 'distinct:ignore_case'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
