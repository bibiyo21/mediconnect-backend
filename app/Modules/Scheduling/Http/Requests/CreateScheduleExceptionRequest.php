<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateScheduleExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked against the doctor and location in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'doctor_profile_id' => ['sometimes', 'uuid'],
            /** Null or absent: every place of this doctor (only the doctor may set that). */
            'clinic_location_id' => ['sometimes', 'nullable', 'uuid'],
            'exception_type' => ['required', 'in:VACATION,SICK_LEAVE,EMERGENCY_LEAVE,PUBLIC_HOLIDAY,CLINIC_CLOSURE,TEMPORARY_UNAVAILABILITY,SPECIAL_HOURS,DOCTOR_REQUESTED_CHANGE,CLINIC_REQUESTED_CHANGE'],
            /** BLOCK (default) or ADD_HOURS; special hours always add. */
            'effect' => ['sometimes', 'in:BLOCK,ADD_HOURS'],
            /** Required when adding hours: the service the extra time is for. */
            'doctor_service_mode_id' => ['sometimes', 'nullable', 'uuid'],
            /** ISO 8601 with an offset, for example 2026-12-24T00:00:00+08:00. */
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'before:+1 year'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_at.after' => 'An exception must start in the future.',
            'ends_at.after' => 'An exception must end after it starts.',
            'ends_at.before' => 'Plan exceptions at most a year ahead.',
        ];
    }
}
