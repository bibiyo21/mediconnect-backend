<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patients module: dependents (database-schema.md §2, CLAUDE.md §4.3).
 *
 * A guardian's link to a person they manage care for. The dependent is a full
 * patient_profiles row without a login. Guardian rights require AUTHORIZED.
 * Revoking a link never deletes the dependent's records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guardian_patient_profile_id')->constrained('patient_profiles')->restrictOnDelete();
            $table->foreignUuid('dependent_patient_profile_id')->constrained('patient_profiles')->restrictOnDelete();
            $table->string('relationship', 30);
            $table->string('authorization_status', 20);
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('consent_record_id')->nullable()->constrained('consent_records')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['guardian_patient_profile_id', 'dependent_patient_profile_id']);
            $table->index('dependent_patient_profile_id');
        });

        DB::statement("ALTER TABLE dependents ADD CONSTRAINT dependents_relationship_check CHECK (relationship IN ('CHILD', 'PARENT', 'SPOUSE', 'SIBLING', 'GRANDPARENT', 'GRANDCHILD', 'LEGAL_WARD', 'OTHER'))");
        DB::statement("ALTER TABLE dependents ADD CONSTRAINT dependents_status_check CHECK (authorization_status IN ('PENDING', 'AUTHORIZED', 'REVOKED'))");
        DB::statement('ALTER TABLE dependents ADD CONSTRAINT dependents_not_self_check CHECK (guardian_patient_profile_id <> dependent_patient_profile_id)');
        DB::statement("ALTER TABLE dependents ADD CONSTRAINT dependents_authorized_check CHECK (authorization_status <> 'AUTHORIZED' OR (authorized_at IS NOT NULL AND consent_record_id IS NOT NULL))");
        DB::statement("ALTER TABLE dependents ADD CONSTRAINT dependents_revoked_check CHECK (authorization_status <> 'REVOKED' OR revoked_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('dependents');
    }
};
