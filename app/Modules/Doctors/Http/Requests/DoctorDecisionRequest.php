<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Verify or activate: step-up on the route; the reason is optional and audited. */
final class DoctorDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission and step-up on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }
}
