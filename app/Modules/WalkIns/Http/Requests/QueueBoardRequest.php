<?php

namespace App\Modules\WalkIns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /walk-ins/queue: the staff board of a location, or (without a location) the caller's own entries. */
final class QueueBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The location whose board to show (staff and doctors). Omit it to list your own walk-in places. */
            'clinic_location_id' => ['sometimes', 'uuid'],
            /** Limits the board to one doctor's queues. */
            'doctor_id' => ['sometimes', 'uuid'],
            /** The queue's local date (Y-m-d); today at the location by default. */
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
