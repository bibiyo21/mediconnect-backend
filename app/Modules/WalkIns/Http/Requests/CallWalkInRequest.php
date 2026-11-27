<?php

namespace App\Modules\WalkIns\Http\Requests;

use App\Modules\WalkIns\Enums\PriorityReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /walk-ins/{id}/call */
final class CallWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /**
             * Only to call a triaged patient (priority or urgent) whose call would overlap a protected appointment window or an active hold.
             * A fixed code, no free text. Needs `walkins.manage_queue`; the override is audited. Call-next never accepts it.
             */
            'override_reason_code' => ['sometimes', 'nullable', Rule::enum(PriorityReason::class)],
        ];
    }

    public function overrideReason(): ?PriorityReason
    {
        $v = $this->input('override_reason_code');

        return is_string($v) ? PriorityReason::from($v) : null;
    }
}
