<?php

namespace App\Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/account/deletion-request: the current password confirms it is the account holder. */
final class AccountDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:128']];
    }
}
