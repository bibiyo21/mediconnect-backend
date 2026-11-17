<?php

use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

it('signs a native client in with tokens bound to a new device', function () {
    $user = account(Role::Patient);

    $response = native_login($user)->assertOk();

    expect($response->json('data.tokens.token_type'))->toBe('Bearer')
        ->and(strlen((string) $response->json('data.tokens.refresh_token')))->toBe(64)
        ->and(DB::table('user_devices')->where('id', $response->json('data.device_id'))->value('platform'))->toBe('IOS')
        ->and(DB::table('personal_access_tokens')->where('user_device_id', $response->json('data.device_id'))->count())->toBe(1);

    $this->getJson('/api/v1/auth/me', bearer($response->json('data.tokens.access_token')))
        ->assertOk()->assertJsonPath('data.id', $user->id);
});

it('accepts the mobile number as the identifier', function () {
    $user = User::factory()->create(['mobile_e164' => '+639171234567', 'password' => TEST_PASSWORD]);

    $this->postJson('/api/v1/auth/login', ['identifier' => '0917 123 4567', 'password' => TEST_PASSWORD])
        ->assertOk()->assertJsonPath('data.user.id', $user->id);
});

it('gives the same answer for an unknown account and a wrong password', function () {
    account(Role::Patient)->forceFill(['email' => 'known@example.com'])->save();

    $wrong = $this->postJson('/api/v1/auth/login', ['identifier' => 'known@example.com', 'password' => 'Wrong-password-1']);
    $unknown = $this->postJson('/api/v1/auth/login', ['identifier' => 'nobody@example.com', 'password' => 'Wrong-password-1']);

    $strip = fn ($r) => collect($r->json())->except('request_id')->all();

    $wrong->assertStatus(401);
    expect($strip($unknown))->toBe($strip($wrong))->and($unknown->status())->toBe(401)
        ->and(DB::table('security_events')->where('event_type', 'LOGIN_FAILED')->count())->toBe(2);
});

it('tells a suspended user only after the correct password', function () {
    $user = account(Role::Patient);
    $user->forceFill(['status' => 'SUSPENDED'])->save();

    $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'Wrong-password-1'])->assertStatus(401);
    native_login($user)->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
});

it('rejects access tokens once they expire', function () {
    $token = native_login(account(Role::Patient))->json('data.tokens.access_token');

    $this->travel(16)->minutes();

    $this->getJson('/api/v1/auth/me', bearer($token))->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('cuts off a signed-in user as soon as the account is suspended', function () {
    $user = account(Role::Patient);
    $token = native_login($user)->json('data.tokens.access_token');

    $user->forceFill(['status' => 'SUSPENDED'])->save();

    $this->getJson('/api/v1/auth/me', bearer($token))->assertStatus(403);
});

it('uses a session cookie, not tokens, for first-party web clients', function () {
    $user = account(Role::Patient);

    $response = $this->withHeader('Origin', WEB_ORIGIN)
        ->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD]);

    $response->assertOk()->assertJsonMissingPath('data.tokens');
    expect(DB::table('user_devices')->where('user_id', $user->id)->value('platform'))->toBe('WEB');

    $this->withHeader('Origin', WEB_ORIGIN)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('limits login attempts per identifier', function () {
    $user = account(Role::Patient);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'Wrong-password-1'])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])
        ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
});

it('signs out a native device: its access and refresh tokens stop working', function () {
    $login = native_login(account(Role::Patient))->json('data');

    $this->postJson('/api/v1/auth/logout', [], bearer($login['tokens']['access_token']))->assertOk();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/auth/me', bearer($login['tokens']['access_token']))->assertStatus(401);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['tokens']['refresh_token']])->assertStatus(401);
    expect(DB::table('user_devices')->where('id', $login['device_id'])->value('revoked_at'))->not->toBeNull();
});
