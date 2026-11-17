<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit module (database-schema.md §15): audit_logs and admin_activity_logs,
 * both append-only.
 *
 * audit_logs is range-partitioned by month on occurred_at, so its primary key
 * is (id, occurred_at). ensure_monthly_partitions() creates months ahead; the
 * DEFAULT partition catches rows if that job ever falls behind, so an audit
 * write never fails for lack of a partition. Row triggers on the parent are
 * inherited by every partition, so the append-only rule covers them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id BIGINT GENERATED ALWAYS AS IDENTITY,
                actor_user_id UUID NULL REFERENCES users(id) ON DELETE RESTRICT,
                actor_roles VARCHAR(255) NULL,
                action VARCHAR(100) NOT NULL,
                subject_type VARCHAR(100) NULL,
                subject_id VARCHAR(64) NULL,
                clinic_id UUID NULL,
                changes JSONB NULL,
                is_access_event BOOLEAN NOT NULL DEFAULT FALSE,
                ip_hash VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                request_id VARCHAR(40) NULL,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement('CREATE INDEX audit_logs_subject_idx ON audit_logs (subject_type, subject_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_user_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_action_idx ON audit_logs (action, occurred_at DESC)');
        DB::statement('CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ensure_monthly_partitions(parent text, months_ahead int) RETURNS int
            LANGUAGE plpgsql
            AS $$
            DECLARE
                created int := 0;
                month_start date;
                partition_name text;
            BEGIN
                FOR i IN 0..months_ahead LOOP
                    month_start := (date_trunc('month', now() AT TIME ZONE 'UTC') + make_interval(months => i))::date;
                    partition_name := format('%s_p%s', parent, to_char(month_start, 'YYYY_MM'));

                    IF to_regclass(partition_name) IS NULL THEN
                        EXECUTE format(
                            'CREATE TABLE %I PARTITION OF %I FOR VALUES FROM (%L) TO (%L)',
                            partition_name, parent,
                            (month_start::timestamp AT TIME ZONE 'UTC'),
                            ((month_start + interval '1 month')::timestamp AT TIME ZONE 'UTC')
                        );
                        created := created + 1;
                    END IF;
                END LOOP;

                RETURN created;
            END
            $$
        SQL);

        DB::statement("SELECT ensure_monthly_partitions('audit_logs', 3)");

        DB::statement(<<<'SQL'
            CREATE TABLE admin_activity_logs (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                admin_user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                action VARCHAR(100) NOT NULL,
                method VARCHAR(10) NULL,
                path VARCHAR(255) NULL,
                status_code SMALLINT NULL,
                target_type VARCHAR(100) NULL,
                target_id VARCHAR(64) NULL,
                reason TEXT NULL,
                step_up_verified BOOLEAN NOT NULL DEFAULT FALSE,
                request_id VARCHAR(40) NULL,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement('CREATE INDEX admin_activity_logs_admin_idx ON admin_activity_logs (admin_user_id, occurred_at DESC)');

        $appRole = (string) config('database.connections.pgsql.username');

        foreach (['audit_logs', 'admin_activity_logs'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION forbid_mutation()");

            if ($appRole !== '' && $appRole !== DB::connection()->getConfig('username')) {
                DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON '.$table.' FROM "'.str_replace('"', '""', $appRole).'"');
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS admin_activity_logs');
        DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS ensure_monthly_partitions(text, int)');
    }
};
