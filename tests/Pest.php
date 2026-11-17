<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../app/Modules/*/Tests/Feature');

pest()->extend(TestCase::class)
    ->in('Unit', '../app/Modules/*/Tests/Unit');
