<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared kernel tables (database-schema.md §17):
 * - idempotency_keys: one stored response per (caller, route, key), 48 hours.
 *   The response body is application-encrypted (it may hold personal data).
 * - outbox_events: domain events written in the same transaction as the state
 *   change, relayed to listeners afterwards, so a crash between commit and
 *   dispatch loses nothing (§24, scenario 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // "user:{uuid}" when signed in, otherwise "ip:{hash}".
            $table->string('scope', 80);
            $table->string('route', 160);
            $table->string('key', 64);
            $table->string('request_hash', 64);
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body_encrypted')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->unique(['scope', 'route', 'key']);
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check CHECK (status IN ('IN_PROGRESS', 'COMPLETED'))");

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 64);
            // Identifiers and non-sensitive facts only; listeners reload state from PostgreSQL.
            $table->jsonb('payload');
            $table->string('request_id', 40)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('dispatched_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
        });

        DB::statement('CREATE INDEX outbox_events_pending_idx ON outbox_events (available_at, id) WHERE dispatched_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
    }
};
