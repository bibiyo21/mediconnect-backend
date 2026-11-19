<?php

use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

/**
 * Signs in a privileged user with MFA. With $stepUp the token also carries a
 * fresh step-up (needed for suspend, roles and settings).
 *
 * @return array{0: User, 1: array<string, string>, 2: string}
 */
function admin_session(Role $role = Role::Admin, bool $stepUp = true): array
{
    app('auth')->forgetGuards(); // the test client caches the previous user between requests
    $admin = account($role);
    [$secret, , $access] = enroll_mfa($admin);
    if ($stepUp) {
        test()->travel(31)->seconds();
        test()->postJson('/api/v1/auth/mfa/step-up', ['code' => current_totp($secret)], bearer($access))->assertOk();
        app('auth')->forgetGuards();
    }

    return [$admin, bearer($access), $secret];
}

/** A fresh step-up for a session started without one. */
function step_up(string $secret, array $headers): void
{
    test()->travel(31)->seconds();
    test()->postJson('/api/v1/auth/mfa/step-up', ['code' => current_totp($secret)], $headers)->assertOk();
    app('auth')->forgetGuards();
}

it('lists users with page pagination, filters and no medical data', function () {
    [, $headers] = admin_session(stepUp: false);
    patient_account();
    $target = patient_account();

    $response = $this->getJson('/api/v1/admin/users?per_page=2', $headers)->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonStructure(['data' => [['id', 'email', 'mobile_masked', 'status', 'roles', 'first_name', 'last_name', 'mfa_enrolled', 'deletion_requested_at', 'last_login_at', 'created_at']], 'meta' => ['page', 'per_page', 'total', 'last_page']]);
    expect($response->json('meta.total'))->toBe(3);

    $this->getJson('/api/v1/admin/users?filter[search]='.urlencode(substr($target->email, 0, 6)), $headers)->assertOk()->assertJsonFragment(['id' => $target->id]);
    $this->getJson('/api/v1/admin/users?filter[role]=ADMIN', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mfa_enrolled', true);
    $this->getJson('/api/v1/admin/users?filter[unknown]=x', $headers)->assertStatus(422);
});

it('refuses users without the permission, and privileged users without MFA', function () {
    $patientHeaders = signed_in(patient_account());
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/admin/users', $patientHeaders)->assertStatus(403);
    $this->app['auth']->forgetGuards();

    $limited = native_login(account(Role::Admin))->json('data.tokens.access_token');
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/admin/users', bearer($limited))->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
});

it('suspends with step-up and a reason, signs the user out, and audits it', function () {
    [$admin, $headers] = admin_session();
    $target = patient_account();
    $targetHeaders = signed_in($target);
    $this->app['auth']->forgetGuards();

    $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Reported abuse, ticket 123'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'SUSPENDED');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', $targetHeaders)->assertStatus(401);
    native_login($target)->assertStatus(403);

    expect(DB::table('audit_logs')->where('action', 'user.suspended')->where('subject_id', $target->id)->value('actor_user_id'))->toBe($admin->id);
    $activity = DB::table('admin_activity_logs')->where('admin_user_id', $admin->id)->sole();
    expect($activity->reason)->toBe('Reported abuse, ticket 123')->and($activity->target_id)->toBe($target->id)->and((bool) $activity->step_up_verified)->toBeTrue();

    $this->postJson("/api/v1/admin/users/{$target->id}/reactivate", ['reason' => 'Appeal accepted'], $headers)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $this->app['auth']->forgetGuards();
    native_login($target)->assertOk();
});

it('requires a fresh step-up and a reason for suspension', function () {
    [, $headers, $secret] = admin_session(stepUp: false);
    $target = patient_account();

    $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Some reason'], $headers)->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    step_up($secret, $headers);
    $this->postJson("/api/v1/admin/users/{$target->id}/suspend", [], $headers)->assertStatus(422)->assertJsonValidationErrors('reason', 'errors');
    expect($target->refresh()->status->value)->toBe('ACTIVE');
});

it('stops admins acting on themselves or on a SUPER_ADMIN', function () {
    [$admin, $headers] = admin_session();
    $super = account(Role::SuperAdmin);

    $this->postJson("/api/v1/admin/users/{$admin->id}/suspend", ['reason' => 'testing self'], $headers)->assertStatus(422);
    $this->postJson("/api/v1/admin/users/{$super->id}/suspend", ['reason' => 'testing super'], $headers)->assertStatus(403);
    expect($super->refresh()->status->value)->toBe('ACTIVE');
});

it('does not let an ADMIN change roles (roles.manage is SUPER_ADMIN only)', function () {
    [, $headers] = admin_session();
    $target = patient_account();

    $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['PATIENT', 'CLINIC_STAFF'], 'reason' => 'Joins the clinic'], $headers)->assertStatus(403);
    expect($target->refresh()->getRoleNames()->all())->toBe(['PATIENT']);
});

it('lets a SUPER_ADMIN change roles, enforcing D-32 and refusing self-changes', function () {
    [$super, $headers] = admin_session(Role::SuperAdmin);
    $target = patient_account();

    $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['PATIENT', 'CLINIC_STAFF'], 'reason' => 'Joins the clinic'], $headers)
        ->assertOk()->assertJsonPath('data.roles', ['PATIENT', 'CLINIC_STAFF']);

    $other = account(Role::MedicalAdmin);
    $this->putJson("/api/v1/admin/users/{$other->id}/roles", ['roles' => ['MEDICAL_ADMIN', 'ADMIN'], 'reason' => 'Should be refused'], $headers)->assertStatus(422);
    expect($other->refresh()->getRoleNames()->all())->toBe(['MEDICAL_ADMIN']);

    $this->putJson("/api/v1/admin/users/{$super->id}/roles", ['roles' => [], 'reason' => 'Removing myself'], $headers)->assertStatus(422);
    expect(DB::table('audit_logs')->where('action', 'user.roles_changed')->count())->toBe(1);
});

it('allows removing a SUPER_ADMIN while another remains', function () {
    [, $headers] = admin_session(Role::SuperAdmin);
    $other = account(Role::SuperAdmin);

    $this->putJson("/api/v1/admin/users/{$other->id}/roles", ['roles' => [], 'reason' => 'Leaving the team'], $headers)->assertOk()->assertJsonPath('data.roles', []);
});
