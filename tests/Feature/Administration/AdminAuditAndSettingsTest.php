<?php

use App\Modules\Rbac\Enums\Role;
use App\Modules\Rbac\Services\RoleAssignment;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

it('shows audit logs to permitted roles with filters and pagination', function () {
    [$admin, $headers] = admin_session(stepUp: false);
    $target = patient_account();
    app(RoleAssignment::class)->assign($target, Role::ClinicStaff);

    $this->getJson('/api/v1/admin/audit-logs?filter[action]=rbac.role_assigned&filter[subject_id]='.$target->id, $headers)->assertOk()
        ->assertJsonPath('data.0.action', 'rbac.role_assigned')
        ->assertJsonPath('data.0.changes.role', 'CLINIC_STAFF')
        ->assertJsonStructure(['meta' => ['page', 'per_page', 'total', 'last_page']]);

    $this->getJson('/api/v1/admin/audit-logs?filter[nope]=1', $headers)->assertStatus(422);
    $patientHeaders = signed_in(patient_account());
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/admin/audit-logs', $patientHeaders)->assertStatus(403);
});

it('lets settings managers read and change settings with step-up and a reason, refusing secrets', function () {
    [$admin, $headers] = admin_session();

    $this->putJson('/api/v1/admin/settings/booking.max_days_ahead', ['value' => 60, 'reason' => 'Longer booking window'], $headers)
        ->assertOk()->assertJsonPath('data.value', 60);
    $this->getJson('/api/v1/admin/settings', $headers)->assertOk()->assertJsonFragment(['key' => 'booking.max_days_ahead', 'value' => 60]);

    $this->putJson('/api/v1/admin/settings/sms.api_key', ['value' => 'abc', 'reason' => 'Trying a secret'], $headers)->assertStatus(422);
    $this->putJson('/api/v1/admin/settings/booking.max_days_ahead', ['value' => 90], $headers)->assertStatus(422)->assertJsonValidationErrors('reason', 'errors');

    expect(DB::table('admin_activity_logs')->where('admin_user_id', $admin->id)->where('target_id', 'booking.max_days_ahead')->value('reason'))->toBe('Longer booking window');
});

it('gives an overview of counts without personal data', function () {
    [, $headers] = admin_session(stepUp: false);
    patient_account();

    $response = $this->getJson('/api/v1/admin/overview', $headers)->assertOk()
        ->assertJsonStructure(['data' => ['users' => ['total', 'active', 'suspended', 'pending_deletion'], 'patients', 'dependents', 'security_events_24h']]);
    expect($response->json('data.users.total'))->toBe(2)->and(json_encode($response->json()))->not->toContain('@');
});
