<?php

namespace App\Modules\Clinics\Http\Requests;

/** PATCH /clinics/{id}/locations/{locationId}/services/{serviceId}: only the fields sent change. */
final class UpdateClinicServiceRequest extends ClinicScopedRequest
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
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160', ClinicRules::NO_PHONE_IN_TEXT],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_duration_minutes' => ['sometimes', 'nullable', 'integer', 'between:5,480'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ClinicRules::messages();
    }
}
