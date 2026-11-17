<?php

namespace App\Modules\Authentication\Http\Requests;

final class ForgotPasswordRequest extends ContactRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->contactRules();
    }
}
