<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Enums\OnboardingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /admin/doctors: the verification queue. Unknown filters are rejected. */
final class ListDoctorsForReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:doctors.verify` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:onboarding_status,search'],
            /** Defaults to the queue: DOCUMENTS_SUBMITTED and UNDER_REVIEW. */
            'filter.onboarding_status' => ['sometimes', Rule::enum(OnboardingStatus::class)],
            /** Matches the start of the display name. */
            'filter.search' => ['sometimes', 'string', 'min:2', 'max:100'],
        ];
    }
}
