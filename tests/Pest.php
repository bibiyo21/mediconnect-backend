<?php

require_once __DIR__.'/Support/auth.php';
require_once __DIR__.'/Support/clinics.php';
require_once __DIR__.'/Support/concurrency.php';
require_once __DIR__.'/Support/directory.php';
require_once __DIR__.'/Support/presence.php';
require_once __DIR__.'/Support/realtime.php';
require_once __DIR__.'/Support/search.php';

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
