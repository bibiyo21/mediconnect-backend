<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /admin/users: page pagination and filters (api-specification.md §1.5). Unknown filters are rejected. */
final class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:users.view` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:search,status,role'],
            /** Matches the start of the email, or the last digits of the mobile number. */
            'filter.search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter.status' => ['sometimes', Rule::enum(UserStatus::class)],
            'filter.role' => ['sometimes', Rule::enum(Role::class)],
        ];
    }
}
