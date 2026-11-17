<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-32: one person may not hold ADMIN (the break-glass reviewer) together with
 * a role that requests clinical grants (SUPER_ADMIN or MEDICAL_ADMIN), so that
 * no one can review their own access.
 *
 * Enforced in the database so every path (seeders, admin UI, tinker) is
 * covered. A transaction-scoped advisory lock per user serializes concurrent
 * role assignments, so two simultaneous inserts cannot both pass the check.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_role_combination() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                new_role text;
                conflicting text;
            BEGIN
                SELECT name INTO new_role FROM roles WHERE id = NEW.role_id;

                IF new_role NOT IN ('ADMIN', 'SUPER_ADMIN', 'MEDICAL_ADMIN') THEN
                    RETURN NEW;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended(NEW.model_type || ':' || NEW.model_id::text, 0));

                SELECT r.name INTO conflicting
                FROM model_has_roles mhr
                JOIN roles r ON r.id = mhr.role_id
                WHERE mhr.model_type = NEW.model_type
                  AND mhr.model_id = NEW.model_id
                  AND (
                        (new_role = 'ADMIN' AND r.name IN ('SUPER_ADMIN', 'MEDICAL_ADMIN'))
                     OR (new_role IN ('SUPER_ADMIN', 'MEDICAL_ADMIN') AND r.name = 'ADMIN')
                  )
                LIMIT 1;

                IF conflicting IS NOT NULL THEN
                    RAISE EXCEPTION 'role_combination_not_allowed: % cannot be combined with %', new_role, conflicting
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER model_has_roles_combination_guard
            BEFORE INSERT OR UPDATE ON model_has_roles
            FOR EACH ROW EXECUTE FUNCTION enforce_role_combination()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS model_has_roles_combination_guard ON model_has_roles');
        DB::statement('DROP FUNCTION IF EXISTS enforce_role_combination()');
    }
};
