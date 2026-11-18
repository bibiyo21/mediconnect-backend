<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Modules\Authentication\Enums\OtpPurpose;
use Illuminate\Validation\Rule;

final class RequestOtpRequest extends ContactRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $purpose = OtpPurpose::tryFrom((string) $this->input('purpose'));

        // Verifying one's own contact uses the address on the account, not the request.
        $contact = $purpose?->requiresAuthentication() ? [] : $this->contactRules();

        // Contact changes need the password and go through POST /auth/contact/otp.
        return ['purpose' => ['required', Rule::enum(OtpPurpose::class)->except([OtpPurpose::ChangeEmail, OtpPurpose::ChangeMobile])], ...$contact];
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from((string) $this->input('purpose'));
    }
}
