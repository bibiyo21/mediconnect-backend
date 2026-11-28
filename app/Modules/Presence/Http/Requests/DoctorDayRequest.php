<?php

namespace App\Modules\Presence\Http\Requests;

use App\Modules\Presence\Services\DoctorDayReader;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /presence/me/day. Own day only (no doctor_id: staff use the clinic console). `date`, when sent, must be today's market date: only today
 * is served in 5.9. Each location is then read for its own local date.
 */
final class DoctorDayRequest extends FormRequest
{
    private ?string $today = null;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('presence.check_in');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Optional. Only today (the market zone's date) is served in this version. */
            'date' => ['sometimes', 'nullable', 'bail', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== $this->localDate()) {
                    $fail('Only today is available.');
                }
            }],
        ];
    }

    /** The market date to serve: today, read once per request. */
    public function localDate(): string
    {
        return $this->today ??= app(DoctorDayReader::class)->today();
    }
}
