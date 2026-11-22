<?php

namespace App\Modules\Clinics\Http\Requests;

/** PATCH /clinics/{id}/locations/{locationId}: only the fields sent change. */
final class UpdateLocationRequest extends ClinicScopedRequest
{
    protected function locationId(): ?string
    {
        $id = $this->route('locationId');

        return is_string($id) ? $id : null;
    }

    protected function permission(): string
    {
        return 'clinics.update';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ClinicRules::location(partial: true);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ClinicRules::messages();
    }
}
