<?php

use App\Modules\Users\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * Real concurrency: two database sessions with committed data, so this suite
 * does not use the per-test transaction of RefreshDatabase. Each test starts
 * and ends with a clean, freshly migrated test database.
 */

function fresh_test_database(): void
{
    expect(config('database.connections.pgsql.database'))->toEndWith('_test');
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
    DB::purge('pgsql_second');
}

beforeEach(function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    fresh_test_database();
    $this->seed(RbacSeeder::class);
});

afterEach(fn () => fresh_test_database());

it('serializes simultaneous ADMIN and SUPER_ADMIN assignments so both cannot succeed', function () {
    $user = User::factory()->create();
    $row = fn (string $role) => [
        'role_id' => Role::findByName($role)->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
    ];

    $first = DB::connection('pgsql');
    $second = DB::connection('pgsql_second');

    // Session 1 assigns SUPER_ADMIN and keeps its transaction open.
    $first->beginTransaction();
    $first->table('model_has_roles')->insert($row('SUPER_ADMIN'));

    // Session 2 tries ADMIN at the same moment: it must wait on the per-user lock.
    $second->beginTransaction();
    $second->statement("SET LOCAL lock_timeout = '300ms'");
    expect(fn () => $second->table('model_has_roles')->insert($row('ADMIN')))
        ->toThrow(QueryException::class, 'lock timeout');
    $second->rollBack();

    // Once session 1 commits, session 2's retry sees SUPER_ADMIN and is refused.
    $first->commit();
    expect(fn () => $second->transaction(fn () => $second->table('model_has_roles')->insert($row('ADMIN'))))
        ->toThrow(QueryException::class, 'role_combination_not_allowed');

    expect($user->refresh()->getRoleNames()->all())->toBe(['SUPER_ADMIN']);
});
