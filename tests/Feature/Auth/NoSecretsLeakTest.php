<?php

use App\Modules\Notifications\Jobs\DeliverOneTimeMessage;
use App\Modules\Notifications\Sms\MailPreviewSmsGateway;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Enums\DeliveryChannel;
use App\Shared\Logging\ScrubSensitiveLogData;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();

    // Capture everything the app logs during the flows, through the real scrubber.
    config(['logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [ScrubSensitiveLogData::class]]]);
    config(['logging.default' => 'capture']);
    Log::forgetChannel('capture');
});

it('never writes passwords, codes or tokens to logs or security events', function () {
    $user = account(Role::Patient);
    $secrets = [TEST_PASSWORD];

    // Login, wrong login, refresh, reset-password with OTP, logout.
    $login = native_login($user)->json('data.tokens');
    $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'Wrong-password-9', 'device' => ['platform' => 'IOS']]);
    $secrets[] = 'Wrong-password-9';
    $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->json('data.tokens');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']]); // reuse -> security event

    $challenge = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->json('data.challenge_id');
    $code = $this->messages->lastCodeFor($user->email);
    $verification = $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code])->json('data.verification_token');
    $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'verification_token' => $verification, 'password' => 'Reset-pass-123', 'password_confirmation' => 'Reset-pass-123']);

    array_push($secrets, $code, $verification, 'Reset-pass-123', $login['refresh_token'], $refreshed['refresh_token'] ?? 'n/a');
    $secrets[] = explode('|', $login['access_token'], 2)[1];

    /** @var TestHandler $handler */
    $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
    $logged = json_encode(array_map(fn ($r) => [$r->message, $r->context, $r->extra], $handler->getRecords()));
    $events = json_encode(DB::table('security_events')->get());

    foreach ($secrets as $secret) {
        expect($logged)->not->toContain($secret)
            ->and($events)->not->toContain($secret);
    }

    expect(DB::table('security_events')->count())->toBeGreaterThan(0);
});

it('encrypts queued code deliveries', function () {
    expect(new DeliverOneTimeMessage(DeliveryChannel::Sms, '+639171234567', 'x', 'code 123456'))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('refuses the local SMS preview driver outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new MailPreviewSmsGateway)->send('+639171234567', 'code'))->toThrow(RuntimeException::class, 'only allowed locally');

    app()->detectEnvironment(fn () => 'testing');
});
