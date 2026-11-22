<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Concurrency tests use real committed data on two sessions, so they cannot
 * run inside RefreshDatabase's transaction. Shared by every file in
 * tests/Concurrency (moved from RoleCombinationRaceTest in 4.7).
 */

/** Clean, freshly migrated test database; refuses to run against anything but *_test. */
function fresh_test_database(): void
{
    expect(config('database.connections.pgsql.database'))->toEndWith('_test');
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
    DB::purge('pgsql_second');
}
