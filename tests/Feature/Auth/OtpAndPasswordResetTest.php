<?php

use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
});

function request_reset(string $email): string
{
    return test()->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202)->json('data.challenge_id');
}

it('locks a challenge after five wrong codes, even if the right code comes next', function () {
    $user = account(Role::Patient);
    $challenge = request_reset($user->email);
    $code = $this->messages->lastCodeFor($user->email);
    $wrong = $code === '111111' ? '222222' : '111111';

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $wrong])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code])->assertStatus(422);
    expect(DB::table('otp_challenges')->where('public_id', $challenge)->value('status'))->toBe('FAILED')
        ->and(DB::table('security_events')->where('event_type', 'OTP_LOCKED')->exists())->toBeTrue();
});

it('expires codes after five minutes', function () {
    $user = account(Role::Patient);
    $challenge = request_reset($user->email);
    $code = $this->messages->lastCodeFor($user->email);

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code])->assertStatus(422);
});

it('never stores the code in the database and keeps only a keyed hash in the cache', function () {
    $user = account(Role::Patient);
    $challenge = request_reset($user->email);
    $code = $this->messages->lastCodeFor($user->email);

    expect(json_encode(DB::table('otp_challenges')->where('public_id', $challenge)->first()))->not->toContain($code)
        ->and(Cache::get('otp:'.$challenge))->toBeString()->not->toBe($code)->not->toContain($code);
});

it('answers forgot-password identically for unknown addresses and sends nothing', function () {
    $user = account(Role::Patient);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

    expect([$unknown->status(), array_keys($unknown->json('data')), $unknown->json('message')])
        ->toBe([$known->status(), array_keys($known->json('data')), $known->json('message')])
        ->and($this->messages->lastCodeFor('nobody@example.com'))->toBeNull()
        ->and($this->messages->notices)->toBe([]);
});

it('limits codes per destination', function () {
    $user = account(Role::Patient);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], ['X-Forwarded-For' => "10.0.0.{$i}"])->assertStatus(202);
        $this->travel(15)->seconds(); // stay under the per-identifier minute limit
    }

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(429);
});

it('resets the password with a verified code and signs out every device', function () {
    $user = account(Role::Patient);
    $phone = native_login($user)->json('data.tokens');

    $challenge = request_reset($user->email);
    $token = $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->messages->lastCodeFor($user->email)])
        ->assertOk()->json('data.verification_token');

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'verification_token' => $token,
        'password' => 'Brand-new-pass-77',
        'password_confirmation' => 'Brand-new-pass-77',
    ])->assertOk();
    app('auth')->forgetGuards();

    expect(Hash::check('Brand-new-pass-77', User::find($user->id)->password))->toBeTrue()
        ->and(DB::table('security_events')->where('event_type', 'PASSWORD_RESET')->exists())->toBeTrue();

    $this->getJson('/api/v1/auth/me', bearer($phone['access_token']))->assertStatus(401);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']])->assertStatus(401);

    // The verification token is single-use.
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email, 'verification_token' => $token,
        'password' => 'Another-pass-88', 'password_confirmation' => 'Another-pass-88',
    ])->assertStatus(422);
});

it('verifies the signed-in user\'s own mobile number', function () {
    $user = account(Role::Patient);
    $user->forceFill(['mobile_e164' => '+639181112222', 'mobile_verified_at' => null])->save();
    $access = native_login($user)->json('data.tokens.access_token');

    $challenge = $this->postJson('/api/v1/auth/otp', ['purpose' => 'VERIFY_MOBILE'], bearer($access))->assertStatus(202)->json('data.challenge_id');
    $token = $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->messages->lastCodeFor('+639181112222')])->json('data.verification_token');

    $this->postJson('/api/v1/auth/verify-mobile', ['verification_token' => $token], bearer($access))->assertOk();

    expect($user->refresh()->mobile_verified_at)->not->toBeNull();
});
