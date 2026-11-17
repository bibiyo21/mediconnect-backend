<?php

use Tests\Concerns\RefreshDatabaseAsOwner;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabaseAsOwner::class)
    ->in('Feature', '../app/Modules/*/Tests/Feature');

pest()->extend(TestCase::class)
    ->in('Unit', '../app/Modules/*/Tests/Unit');
