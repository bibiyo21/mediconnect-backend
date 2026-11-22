<?php

namespace App\Modules\Clinics\Http\Requests;

/** PATCH /clinics/{id}: only the fields sent change. */
final class UpdateClinicRequest extends ClinicScopedRequest
{
    protected function clinicWide(): bool
    {
        return true;
    }

    protected function permission(): string
    {
        return 'clinics.update';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160', ClinicRules::NO_PHONE_IN_TEXT],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ClinicRules::messages();
    }
}
