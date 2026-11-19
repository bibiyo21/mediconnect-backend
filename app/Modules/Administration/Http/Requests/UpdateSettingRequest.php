<?php

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /admin/settings/{key}: any JSON value except null, and a reason. */
final class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:settings.manage` and step-up on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'value' => ['present', 'not_in:'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
