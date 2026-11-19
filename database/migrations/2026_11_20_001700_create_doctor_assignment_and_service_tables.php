<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 2.10: where a doctor practises (doctor_clinic_assignments) and
 * what they offer there at what fee (doctor_services). database-schema.md §4
 * and §5. Fees are integer centavos with a currency (D-18); a fee change ends
 * the old row and starts a new one, so past bookings keep their snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_clinic_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->constrained('clinic_locations')->restrictOnDelete();
            $table->string('status', 10)->default('PENDING');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->foreignUuid('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('end_reason', 200)->nullable();
            $table->jsonb('walkin_policy')->nullable();
            $table->timestampsTz();
            $table->index(['clinic_location_id', 'status']);
            $table->index(['doctor_profile_id', 'status']);
        });
        DB::statement("ALTER TABLE doctor_clinic_assignments ADD CONSTRAINT doctor_clinic_assignments_status_check CHECK (status IN ('PENDING', 'ACTIVE', 'ENDED'))");
        DB::statement("ALTER TABLE doctor_clinic_assignments ADD CONSTRAINT doctor_clinic_assignments_active_check CHECK (status <> 'ACTIVE' OR (effective_from IS NOT NULL AND approved_by IS NOT NULL))");
        DB::statement("ALTER TABLE doctor_clinic_assignments ADD CONSTRAINT doctor_clinic_assignments_ended_check CHECK (status <> 'ENDED' OR effective_until IS NOT NULL)");
        // One open (pending or active) assignment per doctor and location.
        DB::statement("CREATE UNIQUE INDEX doctor_clinic_assignments_open ON doctor_clinic_assignments (doctor_profile_id, clinic_location_id) WHERE status IN ('PENDING', 'ACTIVE')");

        Schema::create('doctor_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->foreignUuid('clinic_service_id')->nullable()->constrained('clinic_services')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('consultation_type', 10);
            $table->boolean('is_follow_up')->default(false);
            $table->smallInteger('duration_minutes');
            $table->smallInteger('buffer_minutes')->default(0);
            $table->bigInteger('fee_amount');
            $table->char('currency', 3)->default('PHP');
            $table->boolean('is_hmo_eligible')->default(false);
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->uuid('replaces_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['doctor_profile_id', 'status']);
            $table->index(['clinic_location_id', 'status']);
        });
        // Self-reference added after the table (and its primary key) exist.
        DB::statement('ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_replaces_fk FOREIGN KEY (replaces_id) REFERENCES doctor_services (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_type_check CHECK (consultation_type IN ('ONLINE', 'IN_PERSON'))");
        // Online services have no location; in-person services always have one.
        DB::statement("ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_location_check CHECK ((consultation_type = 'ONLINE') = (clinic_location_id IS NULL))");
        DB::statement("ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_status_check CHECK (status IN ('ACTIVE', 'ENDED'))");
        DB::statement("ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_ended_check CHECK (status <> 'ENDED' OR effective_until IS NOT NULL)");
        // R-17: every service publishes a fee (zero is shown as free); D-18 integer centavos.
        DB::statement('ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_fee_check CHECK (fee_amount >= 0 AND fee_amount <= 100000000)');
        DB::statement("ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE doctor_services ADD CONSTRAINT doctor_services_duration_check CHECK (duration_minutes BETWEEN 5 AND 240 AND buffer_minutes BETWEEN 0 AND 60)');
        DB::statement("CREATE UNIQUE INDEX doctor_services_active_name ON doctor_services (doctor_profile_id, COALESCE(clinic_location_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(name)) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_services');
        Schema::dropIfExists('doctor_clinic_assignments');
    }
};
