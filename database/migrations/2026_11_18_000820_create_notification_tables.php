<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications module (database-schema.md §14). Tables only in Phase 1; the
 * notification events and endpoints arrive with the features that raise them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel's in-app notifications table, with UUID owners (users.id is a UUID).
        // `data` holds generic text and identifiers only — never diagnoses,
        // prescriptions or membership numbers (§18.3).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('channel', 10);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->primary(['user_id', 'event_type', 'channel']);
        });

        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_channel_check CHECK (channel IN ('IN_APP', 'PUSH', 'EMAIL', 'SMS'))");

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_device_id')->constrained('user_devices')->cascadeOnDelete();
            $table->string('provider', 10);
            $table->string('token', 512)->unique();
            $table->timestampTz('last_registered_at')->useCurrent();
            $table->timestampTz('invalidated_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_provider_check CHECK (provider IN ('FCM', 'APNS'))");

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('provider', 40)->nullable();
            $table->string('status', 20)->default('QUEUED');
            $table->string('provider_message_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['notification_id', 'channel']);
        });

        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check CHECK (channel IN ('IN_APP', 'PUSH', 'EMAIL', 'SMS'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'SKIPPED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
