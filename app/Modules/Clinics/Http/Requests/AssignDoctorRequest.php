<?php

namespace App\Modules\Clinics\Http\Requests;

/**
 * POST /clinics/{id}/doctors: invite a verified doctor to a location, found
 * by PRC licence number. The doctor must accept before the assignment counts.
 */
final class AssignDoctorRequest extends ClinicScopedRequest
{
    protected function permission(): string
    {
        return 'doctors.manage_assignments';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinic_location_id' => ['required', 'uuid'],
            /** The doctor's PRC licence number (digits). Only verified doctors can be found. */
            'prc_license_number' => ['required', 'string', 'regex:/^\d{5,10}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['prc_license_number.regex' => 'Enter the PRC licence number using digits only.'];
    }
}
