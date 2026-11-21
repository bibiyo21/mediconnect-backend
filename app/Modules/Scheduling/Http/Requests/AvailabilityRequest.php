<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public, advisory (§11.6)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** First local date (Y-m-d); default today in the platform time zone. */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            /** Last local date, inclusive; at most 31 days after `from`. */
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'clinic_location_id' => ['sometimes', 'uuid'],
            'doctor_service_id' => ['sometimes', 'uuid'],
            'consultation_type' => ['sometimes', 'in:ONLINE,IN_PERSON'],
        ];
    }
}
