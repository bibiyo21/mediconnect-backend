<?php

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Suspend or reactivate: sensitive, so a reason is required (rbac-permissions.md §7). */
final class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:users.suspend` and step-up on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
