<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor presence (increment 5.1, database-schema.md §7, architecture.md §9).
 * Presence is independent of the schedule: one open session per doctor, and
 * every change is an append-only event carrying the CLAUDE.md §8.5 fields.
 * BUSY is derived from an IN_PROGRESS consultation and is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_presence_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            // Null = an ONLINE session (D-28): a doctor is at one place at a time.
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->string('session_kind', 10);
            $table->string('current_status', 24);
            $table->timestampTz('checked_in_at');
            // The doctor, or staff acting on their behalf.
            $table->foreignUuid('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_out_at')->nullable();
            // Null when the SYSTEM auto-checked the doctor out.
            $table->foreignUuid('checked_out_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('checkout_reason', 20)->nullable();
            // How the check-out happened; SYSTEM (auto check-out) is the only one with no acting user.
            $table->string('checked_out_method', 16)->nullable();
            $table->timestampTz('current_break_expected_end_at')->nullable();
            // Reserved for optional location check-in (D-26); stays null while it is off.
            $table->jsonb('location_evidence')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->index(['doctor_profile_id', 'checked_in_at']);
        });
        DB::statement("ALTER TABLE doctor_presence_sessions ADD CONSTRAINT doctor_presence_sessions_values_check CHECK (
            session_kind IN ('IN_PERSON', 'ONLINE')
            AND current_status IN ('IN', 'OUT', 'ON_BREAK', 'TEMPORARILY_UNAVAILABLE')
            AND (checkout_reason IS NULL OR checkout_reason IN ('END_OF_SHIFT', 'EMERGENCY', 'PERSONAL', 'CLINIC_CLOSED', 'OTHER'))
            AND (checked_out_method IS NULL OR checked_out_method IN ('DOCTOR_APP', 'STAFF_CONSOLE', 'SYSTEM', 'ADMIN'))
            AND version >= 1)");
        DB::statement("ALTER TABLE doctor_presence_sessions ADD CONSTRAINT doctor_presence_sessions_location_check CHECK ((session_kind = 'ONLINE') = (clinic_location_id IS NULL))");
        // A closed session is OUT and states why and how; an open one is never OUT and carries none of that.
        // Only a SYSTEM check-out has no acting user. A break end time only means something while ON_BREAK.
        DB::statement("ALTER TABLE doctor_presence_sessions ADD CONSTRAINT doctor_presence_sessions_closed_check CHECK (
            (checked_out_at IS NOT NULL) = (current_status = 'OUT')
            AND (checked_out_at IS NOT NULL OR (checkout_reason IS NULL AND checked_out_by IS NULL AND checked_out_method IS NULL))
            AND (checked_out_at IS NULL OR (checked_out_at >= checked_in_at AND checkout_reason IS NOT NULL AND checked_out_method IS NOT NULL
                AND ((checked_out_method = 'SYSTEM') = (checked_out_by IS NULL))))
            AND (current_break_expected_end_at IS NULL OR current_status = 'ON_BREAK'))");
        DB::statement('CREATE UNIQUE INDEX uq_one_open_presence_session ON doctor_presence_sessions (doctor_profile_id) WHERE checked_out_at IS NULL');
        DB::statement('CREATE INDEX ix_presence_open_location ON doctor_presence_sessions (clinic_location_id) WHERE checked_out_at IS NULL');

        Schema::create('doctor_presence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('doctor_presence_session_id')->constrained('doctor_presence_sessions')->restrictOnDelete();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->string('previous_status', 24);
            $table->string('new_status', 24);
            // clock_timestamp(), not now(): event order within one transaction stays visible.
            $table->timestampTz('occurred_at', 6)->default(DB::raw('clock_timestamp()'));
            // Null for SYSTEM changes.
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('change_method', 16);
            $table->text('reason')->nullable();
            $table->foreignUuid('source_device_id')->nullable()->constrained('user_devices')->restrictOnDelete();
            $table->jsonb('metadata')->nullable();
        });
        DB::statement("ALTER TABLE doctor_presence_events ADD CONSTRAINT doctor_presence_events_values_check CHECK (
            previous_status IN ('NOT_CHECKED_IN', 'IN', 'OUT', 'ON_BREAK', 'TEMPORARILY_UNAVAILABLE')
            AND new_status IN ('IN', 'OUT', 'ON_BREAK', 'TEMPORARILY_UNAVAILABLE')
            AND change_method IN ('DOCTOR_APP', 'STAFF_CONSOLE', 'SYSTEM', 'ADMIN')
            AND previous_status <> new_status
            AND ((change_method = 'SYSTEM') = (changed_by IS NULL)))");
        DB::statement('CREATE INDEX ix_presence_events_doctor ON doctor_presence_events (doctor_profile_id, occurred_at DESC)');
        DB::statement('CREATE INDEX ix_presence_events_session ON doctor_presence_events (doctor_presence_session_id, id)');
        $this->appendOnly('doctor_presence_events');

        // An event describes its own session: same doctor, same location (a nullable column cannot carry a composite foreign key).
        DB::unprepared("CREATE OR REPLACE FUNCTION doctor_presence_events_match_session() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE v_doctor uuid; v_location uuid;
            BEGIN
                SELECT doctor_profile_id, clinic_location_id INTO v_doctor, v_location FROM doctor_presence_sessions WHERE id = NEW.doctor_presence_session_id;
                IF NOT FOUND OR NEW.doctor_profile_id IS DISTINCT FROM v_doctor OR NEW.clinic_location_id IS DISTINCT FROM v_location THEN
                    RAISE EXCEPTION 'presence event does not match its session' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$");
        DB::statement('CREATE TRIGGER doctor_presence_events_match_session BEFORE INSERT ON doctor_presence_events FOR EACH ROW EXECUTE FUNCTION doctor_presence_events_match_session()');
    }

    public function down(): void
    {
        foreach (['doctor_presence_events', 'doctor_presence_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS doctor_presence_events_match_session()');
    }

    private function appendOnly(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION forbid_mutation()");
        $appRole = (string) config('database.connections.pgsql.username');
        if ($appRole !== '' && $appRole !== DB::connection()->getConfig('username')) {
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON '.$table.' FROM "'.str_replace('"', '""', $appRole).'"');
        }
    }
};
