<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /doctors: start onboarding as a doctor (D-16: the patient profile stays). */
final class StartDoctorOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any signed-in user may apply; verification decides
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The name patients see, for example "Dr. Ana Santos". */
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'professional_title' => ['nullable', 'string', 'max:60'],
            /** The applicant states they are a physician licensed by the PRC; verification follows. */
            'confirm_licensed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm_licensed.accepted' => 'Confirm that you are a physician licensed by the Professional Regulation Commission.', 'confirm_licensed.required' => 'Confirm that you are a physician licensed by the Professional Regulation Commission.'];
    }
}
