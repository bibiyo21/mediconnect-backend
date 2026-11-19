<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Specialties module (database-schema.md §4): clinical reference data curated
 * by MEDICAL_ADMIN (D-39). Never deleted once used; deactivated instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->boolean('is_active')->default(true);
            // D-39: the seeded list starts PENDING_REVIEW until a MEDICAL_ADMIN confirms it.
            $table->string('review_status', 20)->default('PENDING_REVIEW');
            $table->timestampsTz();
        });
        // Alternative names patients type ("OB", "heart doctor"); used by search (Phase 3).
        DB::statement("ALTER TABLE specialties ADD COLUMN synonyms TEXT[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE specialties ADD CONSTRAINT specialties_review_status_check CHECK (review_status IN ('PENDING_REVIEW', 'REVIEWED'))");

        Schema::create('subspecialties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['specialty_id', 'name']);
            $table->unique(['specialty_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subspecialties');
        Schema::dropIfExists('specialties');
    }
};
