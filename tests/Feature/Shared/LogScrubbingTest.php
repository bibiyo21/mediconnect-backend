<?php

use App\Shared\Logging\RedactSensitiveData;
use App\Shared\Logging\ScrubbingLogManager;
use App\Shared\Logging\ScrubSensitiveLogData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

it('attaches the scrubber to every configured log channel', function () {
    // "emergency" is not a channel Laravel taps; it is covered by the next test.
    $unscrubbed = collect(config('logging.channels'))
        ->except('emergency')
        ->reject(fn (array $channel) => in_array(ScrubSensitiveLogData::class, $channel['tap'] ?? [], true))
        ->keys()
        ->all();

    expect($unscrubbed)->toBe([]);
});

it('scrubs the emergency fallback logger', function () {
    $manager = app('log');
    expect($manager)->toBeInstanceOf(ScrubbingLogManager::class);

    $emergency = (fn () => $this->createEmergencyLogger())->call($manager);
    $processors = $emergency->getLogger()->getProcessors();

    expect(collect($processors)->contains(fn ($p) => $p instanceof RedactSensitiveData))->toBeTrue();
});

it('redacts real secret values written through a tapped channel', function () {
    config(['logging.channels.capture' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'tap' => [ScrubSensitiveLogData::class],
    ]]);

    $password = 'S3cret-'.bin2hex(random_bytes(6));
    $otp = (string) random_int(100000, 999999);
    $token = bin2hex(random_bytes(20));

    Context::add('request_id', '01JTEST00000000000000000000');
    $logger = Log::channel('capture');
    $logger->info("login attempt password={$password}", ['otp' => $otp, 'headers' => ['authorization' => "Bearer {$token}"]]);
    $logger->warning("reset link /reset?token={$token}");

    /** @var TestHandler $handler */
    $handler = $logger->getLogger()->getHandlers()[0];
    $written = json_encode(array_map(fn ($r) => [$r->message, $r->context, $r->extra], $handler->getRecords()));

    expect($written)->not->toContain($password)
        ->not->toContain($otp)
        ->not->toContain($token)
        ->toContain(RedactSensitiveData::REDACTED)
        ->toContain('01JTEST00000000000000000000');
});
