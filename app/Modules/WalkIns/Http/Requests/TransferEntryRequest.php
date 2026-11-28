<?php

namespace App\Modules\WalkIns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /walk-ins/{id}/transfer: the target queue, and the staff acknowledgement of a capacity override (B9). */
final class TransferEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'walkin_queue_id' => ['required', 'uuid'],
            /** Needed only when the target is at its capacity; without it the transfer is refused with CAPACITY_OVERRIDE_REQUIRED. */
            'over_capacity_ack' => ['sometimes', 'boolean'],
        ];
    }
}
