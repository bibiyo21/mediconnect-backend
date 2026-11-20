<?php

namespace Tests;

use App\Shared\Contracts\SearchEngine;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InMemorySearchEngine;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No test reaches a real search engine unless it binds one itself (the live search tests).
        $this->app->instance(SearchEngine::class, new InMemorySearchEngine);
    }
}
