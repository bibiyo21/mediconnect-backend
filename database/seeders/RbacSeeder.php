<?php

namespace Database\Seeders;

use App\Modules\Rbac\Enums\Role as RoleName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles, permissions and the role matrix from config/rbac.php
 * (rbac-permissions.md §5). Idempotent: running it again converges the
 * database on the config, including removing permissions no longer listed.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('rbac.guard');
        /** @var list<string> $permissions */
        $permissions = config('rbac.permissions');
        /** @var array<string, array<string, string>> $grants */
        $grants = config('rbac.grants');
        /** @var list<string> $excludedForSuperAdmin */
        $excludedForSuperAdmin = config('rbac.super_admin_excluded');

        DB::transaction(function () use ($guard, $permissions, $grants, $excludedForSuperAdmin) {
            foreach ($permissions as $name) {
                Permission::findOrCreate($name, $guard);
            }

            Permission::where('guard_name', $guard)->whereNotIn('name', $permissions)->delete();

            foreach (RoleName::cases() as $roleName) {
                $role = Role::findOrCreate($roleName->value, $guard);

                $rolePermissions = $roleName === RoleName::SuperAdmin
                    ? array_values(array_diff($permissions, $excludedForSuperAdmin))
                    : array_keys($grants[$roleName->value] ?? []);

                $role->syncPermissions($rolePermissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
