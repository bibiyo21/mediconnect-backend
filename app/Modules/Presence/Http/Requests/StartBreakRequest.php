<?php

namespace App\Modules\Presence\Http\Requests;

final class StartBreakRequest extends PresenceActionRequest
{
    protected function idFields(): array
    {
        return [...parent::idFields(), 'scheduled_break_id'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** How long the break is expected to last, in minutes (at most the configured maximum, 240 by default). */
            'expected_minutes' => ['required', 'integer', 'min:1', 'max:'.(int) config('presence.max_break_minutes')],
            /** One of the doctor's scheduled breaks at this location, when the break is a scheduled one. */
            'scheduled_break_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
