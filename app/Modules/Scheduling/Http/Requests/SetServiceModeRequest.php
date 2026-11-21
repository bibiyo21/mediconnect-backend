<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Enums\ServiceMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetServiceModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in the controller (own doctor profile, or clinic-scoped)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['service_mode' => ['required', 'string', Rule::enum(ServiceMode::class)]];
    }
}
