<?php

use Tests\Concerns\RefreshDatabaseAsOwner;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabaseAsOwner::class)
    ->in('Feature', '../app/Modules/*/Tests/Feature');

pest()->extend(TestCase::class)
    ->in('Unit', '../app/Modules/*/Tests/Unit');

// Concurrency tests need committed data visible to a second session, so they
// do not use the per-test transaction; they reset the test database themselves.
pest()->extend(TestCase::class)
    ->in('Concurrency');
