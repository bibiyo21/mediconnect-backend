<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/doctors/{id}/reject: the reason is shown to the doctor so they can fix it. */
final class RejectDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:doctors.reject` and step-up on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            /** True asks for more documents rather than rejecting outright; the doctor may resubmit either way. */
            'more_documents' => ['sometimes', 'boolean'],
        ];
    }
}
