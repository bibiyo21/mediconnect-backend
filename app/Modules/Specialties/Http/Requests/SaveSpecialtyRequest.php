<?php

namespace App\Modules\Specialties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /admin/specialties and PATCH /admin/specialties/{id}. */
final class SaveSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:specialties.manage` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $id = $this->route('specialtyId');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120', Rule::unique('specialties', 'name')->ignore($id)],
            'synonyms' => ['sometimes', 'array', 'max:20'],
            'synonyms.*' => ['string', 'min:2', 'max:60', 'distinct:ignore_case'],
            'is_active' => ['sometimes', 'boolean'],
            /** A MEDICAL_ADMIN marks the entry as reviewed (D-39). */
            'reviewed' => ['sometimes', 'boolean'],
        ];
    }
}
