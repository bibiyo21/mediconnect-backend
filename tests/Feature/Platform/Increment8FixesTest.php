<?php

use App\Shared\Logging\SentryScrubber;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Sentry\Event;
use Sentry\Tracing\Span;

/*
 * Regression tests for the increment 8 review findings.
 */

beforeEach(function () {
    Route::middleware('api')->get('/api/v1/_test/ip', fn () => response()->json(['ip' => request()->ip()]));
});

it('ignores X-Forwarded-For when no proxy is trusted (finding 1)', function () {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->getJson('/api/v1/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertJsonPath('ip', '198.51.100.7');
});

it('uses the real client IP behind a trusted proxy, so /ready is not exposed (finding 1)', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);
    $viaProxy = fn (string $client) => $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeader('X-Forwarded-For', $client);

    $viaProxy('203.0.113.9')->getJson('/api/v1/_test/ip')->assertJsonPath('ip', '203.0.113.9');
    $viaProxy('203.0.113.9')->getJson('/api/v1/ready')->assertNotFound();
});

it('keeps per-IP limits separate for clients behind the same proxy (finding 1)', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);
    $verify = fn (string $client) => $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->withHeader('X-Forwarded-For', $client)
        ->postJson('/api/v1/auth/otp/verify', ['challenge_id' => (string) Str::uuid7(), 'code' => '123456']);

    foreach (range(1, 10) as $_) {
        $verify('203.0.113.1')->assertStatus(422);
    }

    $verify('203.0.113.1')->assertStatus(429);
    $verify('203.0.113.2')->assertStatus(422);
});

it('marks authenticated operations in the contract and types the account (findings 2 and 3)', function () {
    $doc = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true);

    expect($doc['paths']['/auth/me']['get']['security'])->toBe([['bearer' => []], ['session' => []]])
        ->and($doc['paths']['/auth/login']['post'])->not->toHaveKey('security')
        ->and($doc['paths']['/health']['get'])->not->toHaveKey('security');

    $account = json_encode($doc['paths']['/auth/me']['get']['responses']['200']);
    expect($account)->toContain('AccountResource');
    expect(array_keys($doc['components']['schemas']['AccountResource']['properties']))
        ->toContain('id', 'email', 'mobile_masked', 'roles', 'profile');
});

it('does not report every failed readiness probe to error tracking (finding 5)', function () {
    Exceptions::fake();
    config(['database.redis.default.host' => 'nowhere.invalid', 'database.redis.default.port' => 1]);
    app('redis')->purge();

    $this->getJson('/api/v1/ready')->assertStatus(503);

    Exceptions::assertNothingReported();
});

it('scrubs performance transactions too (finding 6)', function () {
    $span = new Span;
    $span->setDescription("select * from users where password = 'hunter2'");
    $event = Event::createTransaction();
    $event->setSpans([$span]);
    $event->setRequest(['data' => ['diagnosis' => 'x'], 'url' => '/x?token=abc']);

    $out = SentryScrubber::beforeSendTransaction($event);

    expect($out->getSpans()[0]->getDescription())->not->toContain('hunter2')
        ->and($out->getRequest())->not->toHaveKey('data')
        ->and(config('sentry.before_send_transaction'))->toBe([SentryScrubber::class, 'beforeSendTransaction']);
});
