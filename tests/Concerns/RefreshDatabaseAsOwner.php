<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, but migrations run as the schema owner while the tests run
 * on the default connection (the restricted application role). This keeps the
 * role privileges, for example on append-only tables, under test.
 *
 * Defined as a trait because a method in the using class's own trait takes
 * precedence over RefreshDatabase's; an override in the base TestCase would not.
 */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        RefreshDatabase::migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return array_merge($this->baseMigrateFreshUsing(), ['--database' => 'pgsql_owner']);
    }
}
