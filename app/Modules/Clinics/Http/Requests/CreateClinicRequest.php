<?php

namespace App\Modules\Clinics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** POST /admin/clinics: a clinic in an existing or new organization, optionally with its first location. */
final class CreateClinicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:clinics.create` on the route (global)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required_without:organization', 'prohibits:organization', 'nullable', 'uuid', 'exists:organizations,id'],
            'organization' => ['required_without:organization_id', 'nullable', 'array:name,legal_name,organization_type'],
            'organization.name' => ['required_with:organization', 'string', 'min:2', 'max:160'],
            'organization.legal_name' => ['nullable', 'string', 'max:200'],
            'organization.organization_type' => ['required_with:organization', 'in:CLINIC_GROUP,HOSPITAL,SOLO_PRACTICE'],
            'name' => ['required', 'string', 'min:2', 'max:160', ClinicRules::NO_PHONE_IN_TEXT],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['sometimes', 'array'],
            ...ClinicRules::location('location.'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ClinicRules::messages();
    }

    /** Location rules apply only when a location is sent. */
    public function withValidator(Validator $validator): void
    {
        if (! $this->has('location')) {
            $rules = array_filter($validator->getRules(), fn (string $key) => ! str_starts_with($key, 'location.'), ARRAY_FILTER_USE_KEY);
            $validator->setRules($rules);
        }
    }
}
