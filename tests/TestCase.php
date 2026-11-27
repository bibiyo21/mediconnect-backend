<?php

namespace Tests;

use App\Modules\Discovery\Services\SearchIndexer;
use App\Shared\Contracts\SearchEngine;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InMemorySearchEngine;

abstract class TestCase extends BaseTestCase
{
    /** Takes the test-run lock before any migration (RefreshDatabase runs in setUp, after this). */
    public function createApplication()
    {
        $app = parent::createApplication();
        acquire_test_run_lock();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // No test reaches a real search engine unless it binds one itself (the live search tests).
        $this->app->instance(SearchEngine::class, new InMemorySearchEngine);
        SearchIndexer::forgetInstalled();
    }
}
