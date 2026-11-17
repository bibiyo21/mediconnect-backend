<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Migrations run as the schema owner; the tests themselves run on the default
     * connection, which uses the restricted application role. This keeps the
     * role privileges (for example append-only tables) under test.
     */
    protected function migrateFreshUsing(): array
    {
        return array_merge(parent::migrateFreshUsing(), ['--database' => 'pgsql_owner']);
    }
}
