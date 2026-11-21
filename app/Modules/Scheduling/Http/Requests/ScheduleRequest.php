<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST) or change (PATCH) a weekly schedule with its shifts and breaks
 * in one payload. On PATCH every field is optional, `shifts` replaces all
 * shifts, and the version comes from `If-Match` (or `version`).
 */
final class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked against the doctor and location in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'doctor_profile_id' => ['sometimes', 'uuid'],
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['sometimes', 'in:DRAFT,ACTIVE'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'shifts' => [$required, 'array', 'min:1', 'max:50'],
            'shifts.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'shifts.*.doctor_service_mode_id' => ['required', 'uuid'],
            'shifts.*.starts_at' => ['required', 'date_format:H:i'],
            'shifts.*.ends_at' => ['required', 'date_format:H:i', 'after:shifts.*.starts_at'],
            'shifts.*.timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'shifts.*.slot_duration_minutes' => ['required', 'integer', 'between:5,480'],
            'shifts.*.buffer_minutes' => ['sometimes', 'integer', 'between:0,120'],
            'shifts.*.max_appointments' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'shifts.*.walkin_capacity' => ['sometimes', 'nullable', 'integer', 'between:0,500'],
            'shifts.*.breaks' => ['sometimes', 'array', 'max:10'],
            'shifts.*.breaks.*.starts_at' => ['required', 'date_format:H:i'],
            'shifts.*.breaks.*.ends_at' => ['required', 'date_format:H:i', 'after:shifts.*.breaks.*.starts_at'],
            'shifts.*.breaks.*.label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shifts.*.ends_at.after' => 'A shift must end after it starts. Split a shift that runs past midnight into two.',
            'shifts.*.breaks.*.ends_at.after' => 'A break must end after it starts.',
        ];
    }

    /** The version the client last read: If-Match (an ETag or a plain number) or `version`. */
    public function expectedVersion(): ?int
    {
        $header = trim((string) $this->header('If-Match'), ' "W/');
        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        return $this->has('version') ? (int) $this->input('version') : null;
    }
}
