<?php

use App\Modules\Authentication\Models\MfaFactor;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

/** Enrolls MFA for a privileged user; returns [secret, recovery codes, full access token]. */
function enroll_mfa(User $user): array
{
    $limited = native_login($user)->json('data.tokens.access_token');
    $secret = test()->postJson('/api/v1/auth/mfa/totp/setup', [], bearer($limited))->assertOk()->json('data.secret');
    $confirm = test()->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => current_totp($secret)], bearer($limited))->assertOk()->json('data');
    app('auth')->forgetGuards();

    return [$secret, $confirm['recovery_codes'], $confirm['tokens']['access_token']];
}

it('gives a privileged user without MFA a sign-in that can only set up MFA', function () {
    $admin = account(Role::Admin);

    $login = native_login($admin)->assertOk()->json('data');

    expect($login['mfa']['setup_required'])->toBeTrue()
        ->and($login['tokens']['refresh_token'])->toBeNull();

    $this->getJson('/api/v1/auth/me', bearer($login['tokens']['access_token']))->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
    $this->postJson('/api/v1/auth/mfa/totp/setup', [], bearer($login['tokens']['access_token']))->assertOk();
});

it('lets patients in without MFA', function () {
    expect(native_login(account(Role::Patient))->json('data.mfa.setup_required'))->toBeFalse();
});

it('enrolls TOTP, returns ten single-use recovery codes and upgrades to full access', function () {
    $admin = account(Role::Admin);

    [$secret, $codes, $access] = enroll_mfa($admin);

    expect($codes)->toHaveCount(10)
        ->and(MfaFactor::where('user_id', $admin->id)->value('confirmed_at'))->not->toBeNull()
        ->and(json_encode(DB::table('mfa_factors')->first()))->not->toContain($secret)->not->toContain($codes[0]);

    $this->getJson('/api/v1/auth/me', bearer($access))->assertOk();
});

it('requires the second factor at every login once enrolled', function () {
    $admin = account(Role::Admin);
    [$secret] = enroll_mfa($admin);
    $this->travel(31)->seconds(); // the enrollment code's time step is now used up

    $first = native_login($admin)->assertOk()->json('data');
    expect($first)->toHaveKey('mfa_required', true)->not->toHaveKey('tokens');

    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $first['mfa_challenge_id'], 'code' => '000000'])->assertStatus(422);

    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $first['mfa_challenge_id'], 'code' => current_totp($secret)])
        ->assertOk()->assertJsonPath('data.mfa.setup_required', false)->assertJsonStructure(['data' => ['tokens' => ['refresh_token']]]);
});

it('refuses to accept the same TOTP code twice (replay)', function () {
    $admin = account(Role::Admin);
    [$secret, , $access] = enroll_mfa($admin);
    $this->travel(31)->seconds();

    $code = current_totp($secret);
    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $code], bearer($access))->assertOk();
    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $code], bearer($access))->assertStatus(422);
});

it('refuses a code that matched the next time step from being reused (google2fa first-use edge)', function () {
    $admin = account(Role::Admin);
    [$secret, , $access] = enroll_mfa($admin);
    $this->travel(31)->seconds();

    $next = current_totp($secret, offsetSteps: 1);
    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $next], bearer($access))->assertOk();
    $this->travel(30)->seconds();
    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $next], bearer($access))->assertStatus(422);
});

it('accepts each recovery code once', function () {
    $admin = account(Role::Admin);
    [, $codes] = enroll_mfa($admin);

    $login = fn () => native_login($admin)->json('data.mfa_challenge_id');

    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $login(), 'recovery_code' => $codes[0]])->assertOk();
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $login(), 'recovery_code' => $codes[0]])->assertStatus(422);

    expect(DB::table('security_events')->where('event_type', 'RECOVERY_CODE_USED')->count())->toBe(1);
});

it('drops the pending login after five wrong second-factor codes', function () {
    $admin = account(Role::Admin);
    [$secret] = enroll_mfa($admin);
    $this->travel(31)->seconds();
    $challenge = native_login($admin)->json('data.mfa_challenge_id');

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $challenge, 'code' => '000000']);
    }

    // Past the per-minute rate limit, the challenge itself is gone: the right code no longer works.
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $challenge, 'code' => current_totp($secret)])->assertStatus(422);
});

it('guards sensitive routes with a fresh step-up bound to the same token', function () {
    Route::middleware(['api', 'auth:sanctum', 'signin.valid', 'mfa.satisfied', 'step-up'])
        ->post('/api/v1/_test/sensitive', fn () => response()->json(['ok' => true]));

    $admin = account(Role::Admin);
    [$secret, , $access] = enroll_mfa($admin);
    $this->travel(31)->seconds();

    $this->postJson('/api/v1/_test/sensitive', [], bearer($access))->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');

    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => current_totp($secret)], bearer($access))->assertOk();
    $this->postJson('/api/v1/_test/sensitive', [], bearer($access))->assertOk();

    $this->travel(6)->minutes();
    $this->postJson('/api/v1/_test/sensitive', [], bearer($access))->assertStatus(401);
});
