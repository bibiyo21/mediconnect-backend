<?php

namespace App\Modules\Clinics\Http\Requests;

/** POST /clinics/{id}/members: an existing MediConnect account, by email or mobile. */
final class AddMemberRequest extends ClinicScopedRequest
{
    protected function permission(): string
    {
        return 'clinics.manage_staff';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required_without:mobile', 'prohibits:mobile', 'nullable', 'string', 'email:rfc', 'max:254'],
            'mobile' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'member_role' => ['required', 'in:CLINIC_MANAGER,CLINIC_STAFF'],
            /** Null = every location of the clinic. */
            'clinic_location_id' => ['nullable', 'uuid'],
        ];
    }
}
