<?php

namespace App\Modules\Rbac\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Rbac\Exceptions\RoleCombinationNotAllowed;
use App\Modules\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place roles are granted or removed. The database trigger
 * model_has_roles_combination_guard is the final guard; this service gives a
 * clear domain error first and turns a lost race into the same error.
 *
 * Every change is written to the audit log in the same transaction.
 */
final class RoleAssignment implements AssignsRoles
{
    public function __construct(private readonly RecordsAudit $audit) {}

    public function assign(User $user, Role $role): void
    {
        if ($user->hasRole($role->value)) {
            return;
        }

        foreach ($role->incompatibleWith() as $incompatible) {
            if ($user->hasRole($incompatible->value)) {
                throw RoleCombinationNotAllowed::between($role, $incompatible);
            }
        }

        try {
            DB::transaction(function () use ($user, $role) {
                $user->assignRole($role->value);
                $this->audit->record('rbac.role_assigned', 'user', $user->id, ['role' => $role->value]);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'role_combination_not_allowed')) {
                $user->unsetRelation('roles');
                $held = collect($role->incompatibleWith())->first(fn (Role $r) => $user->hasRole($r->value)) ?? $role->incompatibleWith()[0];

                throw RoleCombinationNotAllowed::between($role, $held);
            }

            throw $e;
        }
    }

    public function revoke(User $user, Role $role): void
    {
        if (! $user->hasRole($role->value)) {
            return;
        }

        DB::transaction(function () use ($user, $role) {
            $user->removeRole($role->value);
            $this->audit->record('rbac.role_revoked', 'user', $user->id, ['role' => $role->value]);
        });
    }
}
