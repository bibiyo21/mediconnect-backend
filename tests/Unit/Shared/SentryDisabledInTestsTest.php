<?php

use App\Shared\Exceptions\DomainException;

/*
 * Regression: the suite used to run with the developer's Sentry DSN, so every
 * deliberately thrown test exception was reported to the real Sentry project.
 */
it('never sends test-run errors to Sentry', function () {
    expect(config('sentry.dsn'))->toBeEmpty()
        ->and(app()->environment())->toBe('testing');
});

it('does not report expected domain failures to Sentry', function () {
    expect(config('sentry.ignore_exceptions'))->toContain(DomainException::class);
});
