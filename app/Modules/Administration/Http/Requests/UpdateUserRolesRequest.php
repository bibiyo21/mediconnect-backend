<?php

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Rbac\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /admin/users/{id}/roles: the complete set of roles the user should hold, and why. */
final class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:roles.manage` and step-up on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:10'],
            'roles.*' => ['distinct', Rule::enum(Role::class)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return array_values(array_map(fn (string $r) => Role::from($r), (array) $this->input('roles', [])));
    }
}
