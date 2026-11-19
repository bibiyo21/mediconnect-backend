<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clinics module (database-schema.md §4, CLAUDE.md §6). Shared schema with
 * clinic-scoped authorization through clinic_members (D-17). Doctor-clinic
 * assignments arrive with increment 2.10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->string('organization_type', 20);
            $table->text('tin_encrypted')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_type_check CHECK (organization_type IN ('CLINIC_GROUP', 'HOSPITAL', 'SOLO_PRACTICE'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");

        Schema::create('clinics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('organization_id');
        });
        DB::statement("ALTER TABLE clinics ADD CONSTRAINT clinics_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");

        Schema::create('clinic_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('barangay', 100)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->char('country_code', 2)->default('PH');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            // R-16: contact numbers live in structured fields, never in names or notes.
            $table->string('phone', 16)->nullable();
            $table->string('hospital_name', 160)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();
            $table->index('clinic_id');
        });
        DB::statement("ALTER TABLE clinic_locations ADD CONSTRAINT clinic_locations_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE clinic_locations ADD CONSTRAINT clinic_locations_coordinates_check CHECK ((latitude IS NULL) = (longitude IS NULL) AND (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)))');

        Schema::create('clinic_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('member_role', 20);
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE clinic_members ADD CONSTRAINT clinic_members_role_check CHECK (member_role IN ('CLINIC_MANAGER', 'CLINIC_STAFF'))");
        DB::statement("ALTER TABLE clinic_members ADD CONSTRAINT clinic_members_status_check CHECK (status IN ('ACTIVE', 'REMOVED'))");
        // One active membership per user per clinic scope (NULL location = all locations).
        DB::statement("CREATE UNIQUE INDEX clinic_members_one_active ON clinic_members (clinic_id, COALESCE(clinic_location_id, '00000000-0000-0000-0000-000000000000'::uuid), user_id) WHERE status = 'ACTIVE'");

        Schema::create('clinic_operating_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_location_id')->constrained('clinic_locations')->restrictOnDelete();
            $table->smallInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestampsTz();
            $table->index(['clinic_location_id', 'day_of_week']);
        });
        DB::statement('ALTER TABLE clinic_operating_hours ADD CONSTRAINT clinic_hours_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE clinic_operating_hours ADD CONSTRAINT clinic_hours_order_check CHECK (closes_at > opens_at)');

        Schema::create('clinic_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->date('date');
            $table->string('name', 120);
            $table->boolean('is_closed')->default(true);
            $table->time('special_opens_at')->nullable();
            $table->time('special_closes_at')->nullable();
            $table->timestampsTz();
            $table->index(['clinic_id', 'date']);
        });
        DB::statement('ALTER TABLE clinic_holidays ADD CONSTRAINT clinic_holidays_hours_check CHECK (is_closed OR (special_opens_at IS NOT NULL AND special_closes_at IS NOT NULL AND special_closes_at > special_opens_at))');

        Schema::create('clinic_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_location_id')->constrained('clinic_locations')->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->smallInteger('default_duration_minutes')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();
            $table->unique(['clinic_location_id', 'name']);
        });
        DB::statement("ALTER TABLE clinic_services ADD CONSTRAINT clinic_services_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE clinic_services ADD CONSTRAINT clinic_services_duration_check CHECK (default_duration_minutes IS NULL OR default_duration_minutes BETWEEN 5 AND 480)');
    }

    public function down(): void
    {
        foreach (['clinic_services', 'clinic_holidays', 'clinic_operating_hours', 'clinic_members', 'clinic_locations', 'clinics', 'organizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
