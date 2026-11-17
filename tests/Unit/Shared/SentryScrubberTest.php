<?php

use App\Shared\Logging\SentryScrubber;
use Monolog\Formatter\JsonFormatter;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

it('drops request bodies and cookies and redacts headers, URLs and messages', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://api.example.ph/v1/x?token=abc123',
        'data' => ['diagnosis' => 'clinical text', 'membership_number' => 'M-1'],
        'cookies' => ['mediconnect_session' => 'secret-session'],
        'headers' => ['Authorization' => 'Bearer tok', 'Accept' => 'application/json'],
    ]);
    $event->setExtra(['password' => 'hunter2', 'step' => 3]);
    $event->setMessage('failed for otp_code=123456');
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('SQL error password=hunter2'))]);

    $out = SentryScrubber::beforeSend($event);
    $request = $out->getRequest();

    expect($request)->not->toHaveKeys(['data', 'cookies'])
        ->and($request['headers'])->toBe(['Authorization' => '[REDACTED]', 'Accept' => 'application/json'])
        ->and($request['url'])->not->toContain('abc123')
        ->and($out->getExtra())->toBe(['password' => '[REDACTED]', 'step' => 3])
        ->and($out->getMessage())->not->toContain('123456')
        ->and($out->getExceptions()[0]->getValue())->not->toContain('hunter2');
});

it('scrubs breadcrumbs', function () {
    $crumb = new Breadcrumb('info', 'default', 'log', 'login password=hunter2', ['token' => 'abc', 'n' => 1]);

    $out = SentryScrubber::beforeBreadcrumb($crumb);

    expect($out->getMessage())->not->toContain('hunter2')
        ->and($out->getMetadata())->toBe(['token' => '[REDACTED]', 'n' => 1]);
});

it('never sends personal data or SQL bindings', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBe([SentryScrubber::class, 'beforeSend']);
});

it('writes stderr logs as JSON', function () {
    expect(config('logging.channels.stderr.formatter'))->toBe(JsonFormatter::class);
});
