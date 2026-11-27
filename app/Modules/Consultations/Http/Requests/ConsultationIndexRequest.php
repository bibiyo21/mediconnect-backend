<?php

namespace App\Modules\Consultations\Http\Requests;

use App\Modules\Consultations\Enums\ConsultationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for GET /consultations. `from` and `to` are instants (UTC) on the consultation's creation time; `to` is exclusive. */
final class ConsultationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ConsultationStatus::class)],
            'clinic_location_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ];
    }

    /** @return array{status: string|null, clinic_location_id: string|null, from: CarbonImmutable|null, to: CarbonImmutable|null} */
    public function filters(): array
    {
        return [
            'status' => $this->validated('status'),
            'clinic_location_id' => $this->validated('clinic_location_id'),
            'from' => $this->filled('from') ? CarbonImmutable::parse((string) $this->validated('from'))->utc() : null,
            'to' => $this->filled('to') ? CarbonImmutable::parse((string) $this->validated('to'))->utc() : null,
        ];
    }
}
