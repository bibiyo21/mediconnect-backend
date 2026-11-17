<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outbox dead-letter: an event that keeps failing is parked (failed_at) after
 * config('outbox.max_attempts') instead of retrying forever, raises a critical
 * alert, and can be retried by an operator with `outbox:retry`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outbox_events ADD COLUMN failed_at TIMESTAMPTZ NULL');
        DB::statement('DROP INDEX outbox_events_pending_idx');
        DB::statement('CREATE INDEX outbox_events_pending_idx ON outbox_events (available_at, id) WHERE dispatched_at IS NULL AND failed_at IS NULL');
        DB::statement('CREATE INDEX outbox_events_failed_idx ON outbox_events (failed_at) WHERE failed_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_events_failed_idx');
        DB::statement('DROP INDEX outbox_events_pending_idx');
        DB::statement('ALTER TABLE outbox_events DROP COLUMN failed_at');
        DB::statement('CREATE INDEX outbox_events_pending_idx ON outbox_events (available_at, id) WHERE dispatched_at IS NULL');
    }
};
