<?php

namespace App\Modules\WalkIns\Http\Requests;

use App\Modules\WalkIns\Enums\CloseAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /walk-ins/queues/{id}/close: one disposition per live entry of the queue. */
final class CloseQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** One entry per item; every live entry (REGISTERED, WAITING, SKIPPED) must appear exactly once. Checked against the locked queue. */
            'dispositions' => ['present', 'array'],
            'dispositions.*.entry_id' => ['required', 'uuid', 'distinct'],
            'dispositions.*.action' => ['required', Rule::enum(CloseAction::class)],
        ];
    }
}
