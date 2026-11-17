<?php

use App\Console\Commands\WorkQueues;
use App\Modules\Notifications\Jobs\DeliverOneTimeMessage;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Enums\DeliveryChannel;

/*
 * Regression tests for the increment 6 review findings.
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
});

it('runs workers on every queue a code delivery can use (finding 1)', function (DeliveryChannel $channel) {
    $job = new DeliverOneTimeMessage($channel, 'x', 'subject', 'text');

    expect(WorkQueues::queues())->toContain($job->queue)
        ->and(WorkQueues::queues()[0])->toBe('critical');
})->with([DeliveryChannel::Sms, DeliveryChannel::Email]);

it('runs queued jobs only after the surrounding transaction commits', function () {
    expect(config('queue.connections.redis.after_commit'))->toBeTrue();
});

it('limits step-up attempts per user, not in one bucket shared by everyone (finding 2)', function () {
    $alice = native_login(account(Role::Patient))->json('data.tokens.access_token');
    $bob = native_login(account(Role::Patient))->json('data.tokens.access_token');

    // Patients without MFA: step-up fails with 422, which still counts against the limit.
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => '000000'], bearer($alice))->assertStatus(422);
        app('auth')->forgetGuards();
    }

    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => '000000'], bearer($alice))->assertStatus(429);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => '000000'], bearer($bob))->assertStatus(422);
});

it('ends the refresh tokens issued before MFA was turned on (finding 3)', function () {
    $patient = account(Role::Patient);
    $before = native_login($patient)->json('data.tokens');

    $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], bearer($before['access_token']))->json('data.secret');
    $after = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => current_totp($secret)], bearer($before['access_token']))
        ->assertOk()->json('data.tokens');

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $before['refresh_token']])->assertStatus(401);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $after['refresh_token']])->assertOk();
});

it('refuses verification codes for a suspended account (finding 4)', function () {
    $user = account(Role::Patient);
    $access = native_login($user)->json('data.tokens.access_token');
    $user->forceFill(['status' => 'SUSPENDED'])->save();

    $this->postJson('/api/v1/auth/otp', ['purpose' => 'VERIFY_EMAIL'], bearer($access))->assertStatus(403);

    expect($this->messages->codes)->toBe([]);
});
