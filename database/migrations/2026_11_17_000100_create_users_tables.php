<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users module: users, user_profiles, user_addresses (database-schema.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // email is added below as citext (case-insensitive), which Blueprint cannot express.
            $table->string('mobile_e164', 16)->nullable()->unique();
            $table->string('password');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('mobile_verified_at')->nullable();
            $table->string('status', 40)->default('ACTIVE');
            $table->timestampTz('last_login_at')->nullable();
            $table->string('locale', 10)->default('en');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE users ADD COLUMN email citext NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'SUSPENDED', 'DEACTIVATED'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_contact_check CHECK (email IS NOT NULL OR mobile_e164 IS NOT NULL)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_mobile_format_check CHECK (mobile_e164 ~ '^\\+[1-9][0-9]{7,14}$')");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('en', 'fil'))");

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 20)->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('preferred_language', 10)->default('en');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE user_profiles ADD CONSTRAINT user_profiles_sex_check CHECK (sex IN ('FEMALE', 'MALE', 'OTHER', 'UNKNOWN'))");
        DB::statement("ALTER TABLE user_profiles ADD CONSTRAINT user_profiles_language_check CHECK (preferred_language IN ('en', 'fil'))");

        Schema::create('user_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('barangay', 100)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->char('country_code', 2)->default('PH');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index('user_id');
        });

        DB::statement('CREATE UNIQUE INDEX user_addresses_one_primary ON user_addresses (user_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
    }
};
