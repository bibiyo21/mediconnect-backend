<?php

namespace App\Modules\WalkIns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /walk-ins/queues/{id}/call-next */
final class CallNextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Never accepted: call-next cannot override a protected appointment window (use `/walk-ins/{id}/call`). */
            'override_reason_code' => ['prohibited'],
        ];
    }
}
