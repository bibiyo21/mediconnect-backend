<?php

namespace App\Modules\WalkIns\Http\Requests;

use App\Modules\WalkIns\Enums\CancelReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /walk-ins/{id}/cancel */
final class CancelWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** PATIENT_REQUEST, PATIENT_LEFT, DUPLICATE_ENTRY or OTHER. A fixed code: no free text. The system-only codes are never accepted here. */
            'reason_code' => ['required', Rule::enum(CancelReason::class)->only([
                CancelReason::PatientRequest, CancelReason::PatientLeft, CancelReason::DuplicateEntry, CancelReason::Other,
            ])],
            /** Staff cancelling a guest's entry must confirm they told the patient in person. */
            'patient_informed_in_person' => ['sometimes', 'boolean'],
        ];
    }
}
