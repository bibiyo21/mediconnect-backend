<?php

namespace App\Modules\WalkIns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /walk-ins/join-preview?doctor_service_id= */
final class JoinPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('walkins.register') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The in-person doctor service the patient is thinking of joining. */
            'doctor_service_id' => ['required', 'uuid'],
        ];
    }
}
