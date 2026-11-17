<?php

/*
 * Regression: the suite used to run with the developer's Sentry DSN, so every
 * deliberately thrown test exception was reported to the real Sentry project.
 */
it('never sends test-run errors to Sentry', function () {
    expect(config('sentry.dsn'))->toBeEmpty()
        ->and(app()->environment())->toBe('testing');
});
