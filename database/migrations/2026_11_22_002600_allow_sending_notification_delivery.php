<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A push worker claims a delivery before sending (QUEUED -> SENDING in one UPDATE, stamping claimed_at), so two
 * workers never send the same notification twice; a claim older than the configured window is taken over because
 * its worker died (increment 5.3, SendPushNotification).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->timestampTz('claimed_at')->nullable();
            // Set just before the provider is called: from then on a failed run must not mark the row FAILED (the push may be out).
            $table->timestampTz('provider_called_at')->nullable();
        });
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_status_check');
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('QUEUED', 'SENDING', 'SENT', 'DELIVERED', 'FAILED', 'SKIPPED'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE notification_deliveries SET status = 'QUEUED' WHERE status = 'SENDING'");
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropColumn(['claimed_at', 'provider_called_at']));
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_status_check');
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'SKIPPED'))");
    }
};
