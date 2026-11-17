<?php

use App\Shared\Contracts\Clock;
use App\Shared\Services\SystemClock;
use Carbon\CarbonImmutable;

it('binds the system clock as a singleton', function () {
    expect(app(Clock::class))->toBeInstanceOf(SystemClock::class)
        ->and(app(Clock::class))->toBe(app(Clock::class));
});

it('returns UTC and honours frozen test time', function () {
    CarbonImmutable::setTestNow(new CarbonImmutable('2026-11-17 09:00', 'Asia/Manila'));

    $now = app(Clock::class)->now();

    expect($now->getTimezone()->getName())->toBe('UTC')
        ->and($now->toIso8601ZuluString())->toBe('2026-11-17T01:00:00Z');

    CarbonImmutable::setTestNow();
});
