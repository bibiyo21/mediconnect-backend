<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Verify or activate: step-up on the route and a reason, as for every step-up action (rbac-permissions.md §7). */
final class DoctorDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission and step-up on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
