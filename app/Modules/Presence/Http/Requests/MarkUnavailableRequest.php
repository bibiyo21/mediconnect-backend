<?php

namespace App\Modules\Presence\Http\Requests;

use App\Modules\Presence\Enums\PresenceReason;
use Illuminate\Validation\Rule;

final class MarkUnavailableRequest extends PresenceActionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Why, as a fixed code (SHIFT_START, LATE_ARRIVAL, COVERING_COLLEAGUE, ADMIN_WORK, PHONE_CALL, EMERGENCY, PERSONAL, OTHER). No free text. Kept for the clinic's records; never shown to patients. */
            'reason' => ['sometimes', 'nullable', Rule::enum(PresenceReason::class)],
        ];
    }

    public function reason(): ?PresenceReason
    {
        $value = $this->validated('reason');

        return is_string($value) ? PresenceReason::from($value) : null;
    }
}
