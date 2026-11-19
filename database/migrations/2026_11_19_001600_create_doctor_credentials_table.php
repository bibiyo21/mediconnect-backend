<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credential documents a doctor submits for verification (CLAUDE.md §5.2,
 * increment 2.8). Files live encrypted in the private documents disk (D-36);
 * a row is downloadable only once its scan is CLEAN (D-07). The PRC licence
 * number itself stays on doctor_profiles (encrypted, hashed for uniqueness).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('credential_type', 20);
            // Object key in the private disk; never the original file name.
            $table->string('file_path', 255)->unique();
            $table->string('original_filename', 120);
            $table->string('mime_type', 60);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('PENDING_SCAN');
            $table->string('scan_signature', 200)->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->string('status', 20)->default('SUBMITTED');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['doctor_profile_id', 'deleted_at']);
            $table->index(['scan_status']);
        });

        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_type_check CHECK (credential_type IN ('PRC_ID', 'SPECIALTY_BOARD', 'DIPLOMA', 'OTHER'))");
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_scan_check CHECK (scan_status IN ('PENDING_SCAN', 'CLEAN', 'QUARANTINED', 'SCAN_FAILED'))");
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_status_check CHECK (status IN ('SUBMITTED', 'ACCEPTED', 'REJECTED'))");
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_mime_check CHECK (mime_type IN ('application/pdf', 'image/jpeg', 'image/png'))");
        // A verdict always has a time; a quarantined file always names what was found.
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_scanned_check CHECK (scan_status = 'PENDING_SCAN' OR scanned_at IS NOT NULL)");
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_quarantine_check CHECK (scan_status <> 'QUARANTINED' OR scan_signature IS NOT NULL)");
        // Only a scanned, clean document can be accepted by a reviewer.
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_accept_check CHECK (status <> 'ACCEPTED' OR (scan_status = 'CLEAN' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");
        DB::statement('ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_size_check CHECK (size_bytes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_credentials');
    }
};
