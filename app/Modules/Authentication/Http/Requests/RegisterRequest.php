<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Shared\Enums\Sex;
use App\Shared\Validation\BirthDate;
use Illuminate\Validation\Rule;

final class RegisterRequest extends ContactRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->contactRules(),
            'verification_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', 'max:128', self::passwordRule()],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // D-37: account holders must be adults (minors are dependents).
            'date_of_birth' => BirthDate::accountHolder(),
            'sex' => ['nullable', Rule::enum(Sex::class)],
            // The client must show and send the versions it displayed; stale apps are refused.
            'accept_privacy_notice_version' => ['required', 'string', Rule::in([config('authentication.legal.privacy_notice_version')])],
            'accept_terms_version' => ['required', 'string', Rule::in([config('authentication.legal.terms_version')])],
            ...DeviceRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return BirthDate::messages('date_of_birth');
    }

    /** @return array{first_name: string, last_name: string, middle_name: string|null, date_of_birth: string, sex: string|null} */
    public function identity(): array
    {
        return [
            'first_name' => (string) $this->string('first_name')->trim(),
            'middle_name' => $this->filled('middle_name') ? (string) $this->string('middle_name')->trim() : null,
            'last_name' => (string) $this->string('last_name')->trim(),
            'date_of_birth' => (string) $this->input('date_of_birth'),
            'sex' => $this->input('sex'),
        ];
    }
}
