<?php

namespace App\Modules\Clinics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** End (or withdraw) an assignment: a short reason is kept with it. */
final class EndAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks clinic scope or ownership
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:200']];
    }
}
