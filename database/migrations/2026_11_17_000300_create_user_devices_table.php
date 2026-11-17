<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication module: user_devices, a signed-in device or browser (D-15).
 * Parent of access tokens, refresh tokens (increment 6) and push tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('platform', 20);
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            // HMAC of the IP address: lets security review spot changes without storing the address.
            $table->string('last_ip_hash', 64)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
        });

        DB::statement("ALTER TABLE user_devices ADD CONSTRAINT user_devices_platform_check CHECK (platform IN ('IOS', 'ANDROID', 'WEB'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
