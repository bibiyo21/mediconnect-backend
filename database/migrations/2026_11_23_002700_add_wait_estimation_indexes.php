<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the walk-in wait estimator (increment 5.4). RollingDurationStats finds a doctor's most
 * recent completed consultations, and the consultations of appointments booked for a set of doctor
 * services, so both lookups need an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX ix_appointments_doctor_service ON appointments (doctor_service_id)');
        DB::statement("CREATE INDEX ix_consultations_doctor_completed ON consultations (doctor_profile_id, ended_at DESC) WHERE status = 'COMPLETED'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_consultations_doctor_completed');
        DB::statement('DROP INDEX IF EXISTS ix_appointments_doctor_service');
    }
};
