<?php

namespace App\Modules\Authentication\Http\Requests;

/** PUT /auth/contact: the new email or mobile and the token from POST /auth/otp/verify. */
final class ConfirmContactChangeRequest extends ContactRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->contactRules(),
            'verification_token' => ['required', 'string', 'size:64'],
        ];
    }
}
