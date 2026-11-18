<?php

namespace App\Modules\Authentication\Http\Requests;

/** POST /auth/contact/otp: the new email or mobile, and the current password. */
final class RequestContactChangeCodeRequest extends ContactRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->contactRules(),
            'current_password' => ['required', 'string', 'max:128'],
        ];
    }
}
