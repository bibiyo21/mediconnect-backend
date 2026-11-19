<?php

namespace App\Modules\Clinics\Http\Requests;

/** POST /clinics/{id}/locations. */
final class CreateLocationRequest extends ClinicScopedRequest
{
    protected function permission(): string
    {
        return 'clinics.update';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ClinicRules::location();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ClinicRules::messages();
    }
}
