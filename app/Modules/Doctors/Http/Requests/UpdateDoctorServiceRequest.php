<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /doctors/me/services/{id}: a change ends this row and starts a replacement. */
final class UpdateDoctorServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // own profile only, resolved by the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'is_follow_up' => ['sometimes', 'boolean'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:240', 'multiple_of:5'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            /** Centavos (D-18). */
            'fee_amount' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'is_hmo_eligible' => ['sometimes', 'boolean'],
        ];
    }
}
