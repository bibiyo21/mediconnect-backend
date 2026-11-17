<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * These tests run on the default connection, i.e. as the restricted
 * application role (migrations ran as the schema owner).
 */

it('runs against the test database as the application role', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe('mediconnect_test')
        ->and(DB::scalar('SELECT current_user'))->toBe(config('database.connections.pgsql.username'))
        ->and(DB::scalar('SELECT current_user'))->not->toBe(config('database.connections.pgsql_owner.username'));
});

it('has the required extensions', function () {
    $installed = DB::table('pg_extension')->pluck('extname')->all();

    expect($installed)->toContain('pg_trgm', 'unaccent', 'btree_gist', 'citext');
});

it('provides an immutable unaccent wrapper usable in indexes', function () {
    expect(DB::scalar("SELECT f_unaccent('Parañaque Médical')"))->toBe('Paranaque Medical')
        ->and(DB::scalar("SELECT provolatile FROM pg_proc WHERE proname = 'f_unaccent'"))->toBe('i');
});

it('forbids changes to rows of an append-only table', function () {
    // A temporary table keeps this independent of the real append-only tables (increment 7).
    DB::statement('CREATE TEMP TABLE append_only_probe (id int)');
    DB::statement('CREATE TRIGGER probe_no_change BEFORE UPDATE OR DELETE ON append_only_probe FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');
    DB::table('append_only_probe')->insert(['id' => 1]);

    expect(fn () => DB::table('append_only_probe')->update(['id' => 2]))
        ->toThrow(QueryException::class, 'append_only_probe is append-only');
});

it('does not let the application role change the schema', function (string $sql) {
    // 42501 = insufficient_privilege ("permission denied" or "must be owner").
    expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(QueryException::class, '42501');
})->with([
    'create table' => ['CREATE TABLE public.not_allowed (id int)'],
    'drop table' => ['DROP TABLE users'],
    'alter table' => ['ALTER TABLE users ADD COLUMN sneaky text'],
]);
