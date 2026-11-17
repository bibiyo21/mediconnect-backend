<?php

namespace App\Modules\Authentication\Http\Requests;

final class ResetPasswordRequest extends ContactRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->contactRules(),
            'verification_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', 'max:128', self::passwordRule()],
        ];
    }
}
