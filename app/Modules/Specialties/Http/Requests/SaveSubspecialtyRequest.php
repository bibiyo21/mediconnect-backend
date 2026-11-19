<?php

namespace App\Modules\Specialties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/specialties/{id}/subspecialties and PATCH /admin/subspecialties/{id}. */
final class SaveSubspecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:specialties.manage` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
