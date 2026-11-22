<?php

namespace App\Modules\Clinics\Http\Requests;

/** POST /clinics/{id}/holidays: a closure or special hours on one date. */
final class CreateHolidayRequest extends ClinicScopedRequest
{
    protected function locationId(): ?string
    {
        $id = $this->input('clinic_location_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function clinicWide(): bool
    {
        return true;
    }

    protected function permission(): string
    {
        return 'clinics.manage_hours';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Null applies to every location of the clinic. */
            'clinic_location_id' => ['nullable', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'is_closed' => ['required', 'boolean'],
            'special_opens_at' => ['exclude_if:is_closed,true', 'required', 'date_format:H:i'],
            'special_closes_at' => ['exclude_if:is_closed,true', 'required', 'date_format:H:i', 'after:special_opens_at'],
        ];
    }
}
