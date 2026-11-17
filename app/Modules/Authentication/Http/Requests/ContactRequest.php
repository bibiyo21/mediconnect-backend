<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Modules\Authentication\Services\Destination;
use App\Shared\Exceptions\InvalidValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Exactly one of `email` or `mobile`.
 */
abstract class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function contactRules(): array
    {
        return [
            'email' => ['required_without:mobile', 'prohibits:mobile', 'nullable', 'string', 'email:rfc', 'max:254'],
            'mobile' => ['required_without:email', 'nullable', 'string', 'max:20'],
        ];
    }

    public function destination(): Destination
    {
        $email = $this->input('email');

        if (is_string($email) && $email !== '') {
            return Destination::email($email);
        }

        try {
            return Destination::mobile((string) $this->input('mobile'));
        } catch (InvalidValue $e) {
            throw ValidationException::withMessages(['mobile' => [$e->getMessage()]]);
        }
    }

    public static function passwordRule(): Password
    {
        $rule = Password::min((int) config('authentication.password.min_length'))->letters()->numbers();

        return config('authentication.password.check_breached') ? $rule->uncompromised() : $rule;
    }
}
