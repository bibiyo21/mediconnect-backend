<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication module (D-04, database-schema.md §2 and §17):
 * personal_access_tokens (Sanctum, UUID owners, bound to a device),
 * auth_refresh_tokens, otp_challenges, mfa_factors.
 *
 * Secrets are never stored in plain text: tokens are SHA-256 hashes, OTP codes
 * live hashed in Redis only, TOTP secrets are application-encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->foreignUuid('user_device_id')->nullable()->constrained('user_devices')->cascadeOnDelete();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('auth_refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_device_id')->constrained('user_devices')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // All tokens descended from one login share a family; reuse of any used token revokes the family.
            $table->uuid('family_id')->index();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('replaced_by_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('purpose', 30);
            // HMAC of the normalized destination: lets us rate-limit and match without storing it in clear.
            $table->string('destination_hash', 64)->index();
            $table->string('destination_masked', 60);
            $table->string('status', 20)->default('PENDING');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_token_hash', 64)->nullable()->unique();
            $table->timestampTz('verification_token_expires_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_channel_check CHECK (channel IN ('EMAIL', 'SMS'))");
        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_purpose_check CHECK (purpose IN ('REGISTER', 'VERIFY_EMAIL', 'VERIFY_MOBILE', 'RESET_PASSWORD'))");
        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_status_check CHECK (status IN ('PENDING', 'VERIFIED', 'FAILED', 'CONSUMED'))");

        Schema::create('mfa_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('factor_type', 10)->default('TOTP');
            $table->text('secret_encrypted');
            $table->jsonb('recovery_codes_hashed')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            // Last accepted TOTP time step: a code is never accepted twice (replay protection).
            $table->bigInteger('last_used_timestep')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'factor_type']);
        });

        DB::statement("ALTER TABLE mfa_factors ADD CONSTRAINT mfa_factors_type_check CHECK (factor_type IN ('TOTP'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_factors');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('auth_refresh_tokens');
        Schema::dropIfExists('personal_access_tokens');
    }
};
