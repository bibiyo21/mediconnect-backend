<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointments.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'uuid'],
            /** Your own patient profile (default) or a dependant you are authorized for. */
            'patient_profile_id' => ['sometimes', 'uuid'],
        ];
    }
}
