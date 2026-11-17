<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patients module: patient_profiles, core columns (C-1).
 *
 * user_id is nullable: a dependent is a patient without a login of their own
 * (database-schema.md §2). The patient's own identity fields therefore live
 * here, not only in user_profiles (correction C-6). Dependents (the guardian
 * link) arrive in Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 20)->nullable();
            $table->string('emergency_contact_name', 200)->nullable();
            $table->string('emergency_contact_phone', 16)->nullable();
            $table->string('status', 40)->default('ACTIVE');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE patient_profiles ADD CONSTRAINT patient_profiles_sex_check CHECK (sex IN ('FEMALE', 'MALE', 'OTHER', 'UNKNOWN'))");
        DB::statement("ALTER TABLE patient_profiles ADD CONSTRAINT patient_profiles_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        // "Birth date not in the future" is validated in the application: a CHECK using
        // CURRENT_DATE is not immutable and can fail a later dump and restore.
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_profiles');
    }
};
