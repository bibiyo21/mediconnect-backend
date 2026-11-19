<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Enums\ConsultationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /doctors/me/services. The fee is in centavos (D-18): ₱650.00 is 65000. */
final class CreateDoctorServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // own profile only, resolved by the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'consultation_type' => ['required', Rule::enum(ConsultationType::class)],
            /** Required for IN_PERSON (a location where you have an active assignment); not allowed for ONLINE. */
            'clinic_location_id' => ['required_if:consultation_type,IN_PERSON', 'prohibited_if:consultation_type,ONLINE', 'nullable', 'uuid'],
            /** Optional link to one of the location's clinic services. */
            'clinic_service_id' => ['nullable', 'uuid', 'prohibited_if:consultation_type,ONLINE'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'is_follow_up' => ['sometimes', 'boolean'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:240', 'multiple_of:5'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            /** Centavos. Every service publishes a fee (R-17); 0 means free. */
            'fee_amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'is_hmo_eligible' => ['sometimes', 'boolean'],
        ];
    }
}
