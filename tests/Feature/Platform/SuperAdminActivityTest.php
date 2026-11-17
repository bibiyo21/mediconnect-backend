<?php

use App\Modules\Rbac\Enums\Role;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

it('records every state-changing API request by a SUPER_ADMIN', function () {
    $superAdmin = account(Role::SuperAdmin);
    $token = native_login($superAdmin)->json('data.tokens.access_token');

    $this->postJson('/api/v1/auth/logout', [], bearer($token));

    $row = DB::table('admin_activity_logs')->where('admin_user_id', $superAdmin->id)->first();

    expect($row?->method)->toBe('POST')
        ->and($row->path)->toBe('/api/v1/auth/logout')
        ->and($row->request_id)->not->toBeNull();
});

it('does not record reads or other users', function () {
    $patient = native_login(account(Role::Patient))->json('data.tokens.access_token');

    $this->getJson('/api/v1/auth/me', bearer($patient))->assertOk();
    $this->postJson('/api/v1/auth/logout', [], bearer($patient))->assertOk();

    expect(DB::table('admin_activity_logs')->count())->toBe(0);
});
