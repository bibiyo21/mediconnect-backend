<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit module: consent_records and security_events, both append-only
 * (database-schema.md §15, §17). Created now because registration records
 * consent and authentication records security events. The rest of the audit
 * tables arrive in increment 7.
 */
return new class extends Migration
{
    private const APPEND_ONLY = ['consent_records', 'security_events'];

    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('subject_patient_profile_id')->nullable()->constrained('patient_profiles')->restrictOnDelete();
            $table->string('consent_type', 40);
            $table->string('document_version', 40);
            $table->boolean('granted');
            $table->string('context_type', 40)->nullable();
            $table->uuid('context_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->index(['user_id', 'consent_type', 'recorded_at']);
        });

        DB::statement("ALTER TABLE consent_records ADD CONSTRAINT consent_records_type_check CHECK (consent_type IN ('PRIVACY_NOTICE', 'TERMS', 'TELEMEDICINE', 'RECORDING', 'SELF_PAY_FALLBACK', 'GUARDIAN_AUTHORIZATION', 'MARKETING'))");

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 60);
            $table->string('severity', 10);
            $table->string('ip_hash', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('request_id', 40)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_severity_check CHECK (severity IN ('INFO', 'WARNING', 'HIGH'))");

        $appRole = (string) config('database.connections.pgsql.username');

        foreach (self::APPEND_ONLY as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION forbid_mutation()");

            if ($appRole !== '' && $appRole !== DB::connection()->getConfig('username')) {
                DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON '.$table.' FROM "'.str_replace('"', '""', $appRole).'"');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('consent_records');
    }
};
