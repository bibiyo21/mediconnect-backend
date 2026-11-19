<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doctors module (database-schema.md §2 and §5, CLAUDE.md §5). Credentials
 * (2.8), services and fees (2.10) arrive later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('display_name', 160);
            $table->string('professional_title', 60)->nullable();
            $table->text('biography')->nullable();
            // Encrypted at rest; the keyed hash keeps it unique without storing it in clear.
            $table->text('prc_license_number_encrypted')->nullable();
            $table->string('prc_license_number_hash', 64)->nullable()->unique();
            $table->string('license_verification_status', 20)->default('NOT_VERIFIED');
            $table->string('onboarding_status', 30)->default('REGISTERED');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('medical_school', 200)->nullable();
            $table->smallInteger('years_experience')->nullable();
            $table->string('gender', 20)->nullable();
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->integer('review_count')->default(0);
            $table->smallInteger('profile_completeness')->default(0);
            $table->timestampsTz();
            $table->index('onboarding_status');
        });
        DB::statement("ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_onboarding_check CHECK (onboarding_status IN ('REGISTERED', 'DOCUMENTS_SUBMITTED', 'UNDER_REVIEW', 'REJECTED', 'VERIFIED', 'ACTIVE'))");
        DB::statement("ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_license_check CHECK (license_verification_status IN ('NOT_VERIFIED', 'VERIFIED', 'REJECTED'))");
        DB::statement("ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_gender_check CHECK (gender IS NULL OR gender IN ('FEMALE', 'MALE', 'OTHER'))");
        DB::statement('ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_years_check CHECK (years_experience IS NULL OR years_experience BETWEEN 0 AND 70)');
        DB::statement('ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_completeness_check CHECK (profile_completeness BETWEEN 0 AND 100)');
        // VERIFIED and ACTIVE doctors must have a verified licence and a verifier.
        DB::statement("ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_verified_check CHECK (onboarding_status NOT IN ('VERIFIED', 'ACTIVE') OR (license_verification_status = 'VERIFIED' AND verified_at IS NOT NULL))");

        Schema::create('doctor_specialties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->foreignUuid('subspecialty_id')->nullable()->constrained('subspecialties')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('board_certified')->default(false);
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX doctor_specialties_unique ON doctor_specialties (doctor_profile_id, specialty_id, COALESCE(subspecialty_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        DB::statement('CREATE UNIQUE INDEX doctor_specialties_one_primary ON doctor_specialties (doctor_profile_id) WHERE is_primary');

        Schema::create('doctor_languages', function (Blueprint $table) {
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('language_code', 8);
            $table->primary(['doctor_profile_id', 'language_code']);
        });

        Schema::create('doctor_education', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('institution', 200);
            $table->string('degree', 160);
            $table->smallInteger('year_completed')->nullable();
            $table->timestampsTz();
        });

        Schema::create('doctor_experience', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('organization', 200);
            $table->string('position', 160);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE doctor_experience ADD CONSTRAINT doctor_experience_dates_check CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)');

        Schema::create('doctor_verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['doctor_profile_id', 'occurred_at']);
        });
        DB::statement('CREATE TRIGGER doctor_verification_events_append_only BEFORE UPDATE OR DELETE ON doctor_verification_events FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');
        $appRole = (string) config('database.connections.pgsql.username');
        if ($appRole !== '' && $appRole !== DB::connection()->getConfig('username')) {
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON doctor_verification_events FROM "'.str_replace('"', '""', $appRole).'"');
        }
    }

    public function down(): void
    {
        foreach (['doctor_verification_events', 'doctor_experience', 'doctor_education', 'doctor_languages', 'doctor_specialties', 'doctor_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
