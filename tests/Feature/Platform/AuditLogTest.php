<?php

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Rbac\Services\RoleAssignment;
use App\Modules\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seed_roles());

it('audits role changes with the acting user', function () {
    $admin = account(Role::SuperAdmin);
    $target = User::factory()->create();
    Auth::login($admin);

    app(RoleAssignment::class)->assign($target, Role::Doctor);
    app(RoleAssignment::class)->revoke($target, Role::Doctor);
    app(RoleAssignment::class)->revoke($target, Role::Doctor); // no change, no row

    $rows = DB::table('audit_logs')->where('subject_id', $target->id)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['rbac.role_assigned', 'rbac.role_revoked'])
        ->and($rows[0]->actor_user_id)->toBe($admin->id)
        ->and($rows[0]->actor_roles)->toContain('SUPER_ADMIN')
        ->and(json_decode($rows[0]->changes, true))->toBe(['role' => 'DOCTOR']);
});

it('writes audit rows into the current month partition, not the default one', function () {
    app(RecordsAudit::class)->record('test.action', 'thing', '1');

    $partition = 'audit_logs_p'.now('UTC')->format('Y_m');

    expect(DB::table($partition)->count())->toBe(1)
        ->and(DB::table('audit_logs_default')->count())->toBe(0);
});

it('scrubs secrets from recorded changes', function () {
    app(RecordsAudit::class)->record('test.action', 'thing', '1', ['password' => 'hunter2', 'name' => 'Ana']);

    expect(json_decode(DB::table('audit_logs')->value('changes'), true))->toEqual(['password' => '[REDACTED]', 'name' => 'Ana']);
});

it('keeps audit rows append-only for the application role, partitions included', function (string $sql) {
    app(RecordsAudit::class)->record('test.action', 'thing', '1');

    expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(QueryException::class);
})->with([
    'update parent' => ["UPDATE audit_logs SET action = 'tampered'"],
    'delete parent' => ['DELETE FROM audit_logs'],
    'update partition directly' => fn () => 'UPDATE audit_logs_p'.now('UTC')->format('Y_m')." SET action = 'tampered'",
    'truncate' => ['TRUNCATE audit_logs'],
    'admin activity update' => ["UPDATE admin_activity_logs SET action = 'x'"],
]);

it('creates partitions ahead and reports success when the default partition is empty', function () {
    expect(Artisan::call('audit:partitions', ['--months' => 6]))->toBe(0)
        ->and(DB::scalar("SELECT to_regclass('audit_logs_p".now('UTC')->addMonths(6)->format('Y_m')."') IS NOT NULL"))->toBeTrue();
});
