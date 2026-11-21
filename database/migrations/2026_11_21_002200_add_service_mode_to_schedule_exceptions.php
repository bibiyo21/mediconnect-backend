<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 4.4: special hours need to say which service the extra time is
 * for, so the generator knows the slot length and mode. Required exactly
 * when the exception adds hours (schema addition, database-schema.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->foreignUuid('doctor_service_mode_id')->nullable()->after('clinic_location_id')->constrained('doctor_service_modes')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE doctor_schedule_exceptions ADD CONSTRAINT doctor_schedule_exceptions_mode_check CHECK ((effect = 'ADD_HOURS') = (doctor_service_mode_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_schedule_exceptions DROP CONSTRAINT IF EXISTS doctor_schedule_exceptions_mode_check');
        Schema::table('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doctor_service_mode_id');
        });
    }
};
