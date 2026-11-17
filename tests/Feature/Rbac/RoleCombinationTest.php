<?php

use App\Modules\Rbac\Enums\Role as RoleName;
use App\Modules\Rbac\Exceptions\RoleCombinationNotAllowed;
use App\Modules\Rbac\Services\RoleAssignment;
use App\Modules\Users\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RbacSeeder::class));

it('rejects combining the grant reviewer with a grant requester (D-32)', function (RoleName $first, RoleName $second) {
    $user = User::factory()->create();
    $roles = app(RoleAssignment::class);
    $roles->assign($user, $first);

    expect(fn () => $roles->assign($user, $second))->toThrow(RoleCombinationNotAllowed::class)
        ->and($user->refresh()->getRoleNames()->all())->toBe([$first->value]);
})->with([
    [RoleName::Admin, RoleName::SuperAdmin],
    [RoleName::SuperAdmin, RoleName::Admin],
    [RoleName::Admin, RoleName::MedicalAdmin],
    [RoleName::MedicalAdmin, RoleName::Admin],
]);

it('allows roles that are not in conflict, including several at once (§4.2)', function () {
    $user = User::factory()->create();
    $roles = app(RoleAssignment::class);

    foreach ([RoleName::Doctor, RoleName::Patient, RoleName::ClinicManager, RoleName::MedicalAdmin, RoleName::SuperAdmin] as $role) {
        $roles->assign($user, $role);
    }

    expect($user->refresh()->getRoleNames()->sort()->values()->all())
        ->toBe(['CLINIC_MANAGER', 'DOCTOR', 'MEDICAL_ADMIN', 'PATIENT', 'SUPER_ADMIN']);
});

it('blocks the combination in the database even when the service is bypassed', function () {
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');

    $insert = fn () => DB::transaction(fn () => DB::table('model_has_roles')->insert([
        'role_id' => Role::findByName('ADMIN')->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
    ]));

    expect($insert)->toThrow(QueryException::class, 'role_combination_not_allowed');
});

it('renders the domain error as a 422 with a field message', function () {
    $error = RoleCombinationNotAllowed::between(RoleName::Admin, RoleName::SuperAdmin);

    expect($error->errorCode()->httpStatus())->toBe(422)
        ->and($error->errors())->toBe(['role' => ['The ADMIN role cannot be combined with SUPER_ADMIN.']]);
});
