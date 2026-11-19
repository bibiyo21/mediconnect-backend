<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /admin/doctors/{id}/credentials/{credentialId}/review */
final class ReviewCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:doctors.verify` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['ACCEPTED', 'REJECTED'])],
            /** Shown to the doctor; required when rejecting. */
            'note' => ['required_if:decision,REJECTED', 'nullable', 'string', 'max:1000'],
        ];
    }
}
