<?php

namespace App\Modules\WalkIns\Http\Requests;

use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\PriorityReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /walk-ins/{id}/priority */
final class ChangePriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** NORMAL, PRIORITY or URGENT. */
            'priority_level' => ['required', Rule::in(['NORMAL', 'PRIORITY', 'URGENT'])],
            /** Required for PRIORITY and URGENT: a fixed code, no free text. */
            'priority_reason_code' => ['required_if:priority_level,PRIORITY,URGENT', 'nullable', Rule::enum(PriorityReason::class)],
            /** An optional short note for a priority: no symptoms or diagnosis. At most 140 characters. Kept encrypted on the entry; never in events, audit rows or notices. */
            'priority_note' => ['sometimes', 'nullable', 'string', 'max:140'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('priority_level') === 'NORMAL') {
                foreach (['priority_reason_code', 'priority_note'] as $field) {
                    if ($this->filled($field)) {
                        $v->errors()->add($field, 'A reason applies only to a priority above normal.');
                    }
                }
            }
        });
    }

    public function level(): PriorityLevel
    {
        return PriorityLevel::fromApi((string) $this->input('priority_level'));
    }

    public function reason(): ?PriorityReason
    {
        $v = $this->input('priority_reason_code');

        return is_string($v) && $this->level()->needsReason() ? PriorityReason::from($v) : null;
    }

    public function note(): ?string
    {
        return $this->filled('priority_note') ? (string) $this->input('priority_note') : null;
    }
}
