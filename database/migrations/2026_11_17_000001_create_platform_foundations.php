<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform foundations (database-schema.md §1).
 *
 * - Extensions: all four are "trusted" in PostgreSQL 13+, so the database owner
 *   can create them; IF NOT EXISTS keeps this safe where provisioning already did.
 * - f_unaccent(): IMMUTABLE wrapper so unaccent can be used in index expressions.
 * - forbid_mutation(): trigger function for append-only tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pg_trgm', 'unaccent', 'btree_gist', 'citext'] as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = '42501';
            END
            $$
        SQL);
    }

    public function down(): void
    {
        // Extensions are left in place: they are shared, and later objects may need them.
        DB::statement('DROP FUNCTION IF EXISTS forbid_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
    }
};
