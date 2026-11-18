<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Enums\DependentRelationship;
use App\Shared\Enums\Sex;
use App\Shared\Validation\BirthDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /patients/me/dependents/{id}: only the fields sent change. */
final class UpdateDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the authorised link is checked in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'date_of_birth' => ['sometimes', ...BirthDate::anyAge()],
            'sex' => ['sometimes', 'nullable', Rule::enum(Sex::class)],
            'relationship' => ['sometimes', 'required', Rule::enum(DependentRelationship::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return BirthDate::anyAgeMessages('date_of_birth');
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $data = $this->validated();
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) === '' && $field === 'middle_name' ? null : trim($data[$field]);
            }
        }

        return $data;
    }
}
