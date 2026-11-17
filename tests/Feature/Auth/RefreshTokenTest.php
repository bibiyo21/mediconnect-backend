<?php

use App\Modules\Rbac\Enums\Role;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

it('rotates: a refresh gives a new pair and the old access token stops working', function () {
    $first = native_login(account(Role::Patient))->json('data');

    $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['tokens']['refresh_token']])->assertOk()->json('data');
    app('auth')->forgetGuards();

    expect($second['tokens']['refresh_token'])->not->toBe($first['tokens']['refresh_token'])
        ->and($second['device_id'])->toBe($first['device_id']);

    $this->getJson('/api/v1/auth/me', bearer($first['tokens']['access_token']))->assertStatus(401);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', bearer($second['tokens']['access_token']))->assertOk();
});

it('treats reuse of a used refresh token as theft and revokes the whole family', function () {
    $first = native_login(account(Role::Patient))->json('data');
    $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['tokens']['refresh_token']])->json('data');

    // An attacker replays the first (already used) refresh token.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['tokens']['refresh_token']])->assertStatus(401);
    app('auth')->forgetGuards();

    // The legitimate client's newer tokens are dead too.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['tokens']['refresh_token']])->assertStatus(401);
    $this->getJson('/api/v1/auth/me', bearer($second['tokens']['access_token']))->assertStatus(401);

    $event = DB::table('security_events')->where('event_type', 'REFRESH_TOKEN_REUSE')->first();
    expect($event?->severity)->toBe('HIGH')
        ->and(DB::table('user_devices')->where('id', $first['device_id'])->value('revoked_at'))->not->toBeNull();
});

it('refuses expired and unknown refresh tokens', function () {
    $tokens = native_login(account(Role::Patient))->json('data.tokens');

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('x', 64)])->assertStatus(401);

    $this->travel(31)->days();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
});

it('stores only hashes of refresh and access tokens', function () {
    $tokens = native_login(account(Role::Patient))->json('data.tokens');
    [, $accessPlain] = explode('|', $tokens['access_token'], 2);

    $everything = json_encode([DB::table('auth_refresh_tokens')->get(), DB::table('personal_access_tokens')->get()]);

    expect($everything)->not->toContain($tokens['refresh_token'])->not->toContain($accessPlain)
        ->and(DB::table('auth_refresh_tokens')->value('token_hash'))->toBe(hash('sha256', $tokens['refresh_token']));
});

it('does not refresh for a suspended account', function () {
    $user = account(Role::Patient);
    $tokens = native_login($user)->json('data.tokens');
    $user->forceFill(['status' => 'SUSPENDED'])->save();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
});
