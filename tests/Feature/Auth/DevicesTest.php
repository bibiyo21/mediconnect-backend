<?php

use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Authentication\Services\DeviceRevocation;
use App\Modules\Rbac\Enums\Role;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

it('lists only the user\'s own active devices and marks the current one', function () {
    $user = account(Role::Patient);
    native_login($user, 'ANDROID');
    $current = native_login($user)->json('data');
    native_login(account(Role::Patient));

    $devices = $this->getJson('/api/v1/auth/devices', bearer($current['tokens']['access_token']))->assertOk()->json('data');

    expect($devices)->toHaveCount(2)
        ->and(collect($devices)->firstWhere('current', true)['id'])->toBe($current['device_id']);
});

it('signs out another of the user\'s devices', function () {
    $user = account(Role::Patient);
    $other = native_login($user, 'ANDROID')->json('data');
    $current = native_login($user)->json('data.tokens.access_token');

    $this->deleteJson('/api/v1/auth/devices/'.$other['device_id'], [], bearer($current))->assertOk();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/auth/me', bearer($other['tokens']['access_token']))->assertStatus(401);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $other['tokens']['refresh_token']])->assertStatus(401);
});

it('returns 404 for someone else\'s device, the same as for a missing one (IDOR)', function () {
    $victimDevice = native_login(account(Role::Patient))->json('data.device_id');
    $attacker = native_login(account(Role::Patient))->json('data.tokens.access_token');

    $this->deleteJson('/api/v1/auth/devices/'.$victimDevice, [], bearer($attacker))->assertStatus(404);
    $this->deleteJson('/api/v1/auth/devices/'.(string) Str::uuid7(), [], bearer($attacker))->assertStatus(404);
});

it('ends a web session when its device is signed out from elsewhere', function () {
    $user = account(Role::Patient);
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertOk();
    $this->withHeader('Origin', WEB_ORIGIN)->getJson('/api/v1/auth/me')->assertOk();

    // Signed out from another device (the HTTP path is covered above); the test app
    // shares one session store across requests, so the revocation is done directly.
    app(DeviceRevocation::class)->revokeDevice(UserDevice::where('user_id', $user->id)->where('platform', 'WEB')->firstOrFail());
    app('auth')->forgetGuards();

    $this->withHeader('Origin', WEB_ORIGIN)->getJson('/api/v1/auth/me')->assertStatus(401);
});
