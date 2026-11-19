<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-40: a doctor's specialties (and "board certified") are self-reported until
 * a reviewer confirms them. Only confirmed entries may be shown publicly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_specialties', function (Blueprint $table) {
            $table->string('verification_status', 20)->default('SELF_REPORTED');
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
        });
        DB::statement("ALTER TABLE doctor_specialties ADD CONSTRAINT doctor_specialties_verification_check CHECK (verification_status IN ('SELF_REPORTED', 'CONFIRMED'))");
        DB::statement("ALTER TABLE doctor_specialties ADD CONSTRAINT doctor_specialties_confirmed_check CHECK (verification_status <> 'CONFIRMED' OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_specialties DROP CONSTRAINT IF EXISTS doctor_specialties_confirmed_check');
        DB::statement('ALTER TABLE doctor_specialties DROP CONSTRAINT IF EXISTS doctor_specialties_verification_check');
        Schema::table('doctor_specialties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['verification_status', 'confirmed_at']);
        });
    }
};
