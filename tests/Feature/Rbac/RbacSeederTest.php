<?php

use App\Modules\Rbac\Enums\Role as RoleName;
use App\Modules\Rbac\Services\RoleAssignment;
use App\Modules\Users\Models\User;
use Database\Seeders\RbacSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RbacSeeder::class));

function user_with(RoleName ...$roles): User
{
    $user = User::factory()->create();
    foreach ($roles as $role) {
        app(RoleAssignment::class)->assign($user, $role);
    }

    return $user->refresh();
}

it('seeds the ten roles and every permission', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleName::cases())->map->value->sort()->values()->all())
        ->and(Permission::count())->toBe(count(config('rbac.permissions')))
        ->and(Permission::count())->toBe(74);
});

it('is idempotent and removes permissions that are no longer listed', function () {
    Permission::create(['name' => 'legacy.removed', 'guard_name' => 'web']);

    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);

    expect(Permission::count())->toBe(74)
        ->and(Permission::where('name', 'legacy.removed')->exists())->toBeFalse()
        ->and(Role::count())->toBe(10);
});

it('grants each role exactly the permissions in the matrix', function (RoleName $role) {
    $expected = array_keys(config("rbac.grants.{$role->value}"));
    sort($expected);

    $actual = Role::findByName($role->value)->permissions->pluck('name')->sort()->values()->all();

    expect($actual)->toBe($expected);
})->with(fn () => array_filter(RoleName::cases(), fn (RoleName $r) => $r !== RoleName::SuperAdmin));

it('keeps SUPER_ADMIN away from clinical content and grant reviews (D-32)', function () {
    $superAdmin = user_with(RoleName::SuperAdmin);

    foreach (config('rbac.super_admin_excluded') as $permission) {
        expect($superAdmin->can($permission))->toBeFalse("SUPER_ADMIN must not hold {$permission}");
    }

    expect($superAdmin->can('medical_records.break_glass'))->toBeTrue()
        ->and($superAdmin->can('roles.manage'))->toBeTrue()
        ->and($superAdmin->can('payments.reconcile'))->toBeTrue();
});

it('enforces the §20.3 separation rules at the permission level', function (RoleName $role, string $prefix) {
    $user = user_with($role);

    $held = $user->getAllPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, $prefix));

    expect($held->all())->toBe([]);
})->with([
    'finance has no clinical records' => [RoleName::FinanceOfficer, 'medical_records.'],
    'finance has no prescriptions' => [RoleName::FinanceOfficer, 'prescriptions.'],
    'support has no clinical records' => [RoleName::SupportAgent, 'medical_records.'],
    'support has no prescriptions' => [RoleName::SupportAgent, 'prescriptions.'],
    'admin (HMO administration) has no clinical records' => [RoleName::Admin, 'medical_records.view'],
    'clinic staff has no clinical records' => [RoleName::ClinicStaff, 'medical_records.'],
    'clinic manager has no clinical records' => [RoleName::ClinicManager, 'medical_records.'],
    'patients cannot manage roles' => [RoleName::Patient, 'roles.'],
]);

it('gives the moderator only moderation and reporting', function () {
    expect(user_with(RoleName::Moderator)->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['reports.view', 'reviews.moderate', 'users.view']);
});

it('reserves the strictest permissions for SUPER_ADMIN only', function (string $permission) {
    foreach (RoleName::cases() as $role) {
        if ($role === RoleName::SuperAdmin) {
            continue;
        }

        expect(Role::findByName($role->value)->hasPermissionTo($permission))->toBeFalse("{$role->value} holds {$permission}");
    }
})->with(['roles.manage', 'permissions.manage', 'audit_logs.export', 'medical_records.break_glass']);

it('checks permissions through the Laravel gate', function () {
    $doctor = user_with(RoleName::Doctor);

    expect($doctor->can('presence.check_in'))->toBeTrue()
        ->and($doctor->can('payouts.process'))->toBeFalse()
        ->and($doctor->can('not.a.permission'))->toBeFalse();
});
