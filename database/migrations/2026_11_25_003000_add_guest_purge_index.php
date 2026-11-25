<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The daily guest-data purge (increment 5.5a) looks for guest entries not yet purged, oldest first. A partial index keeps that
 * scan on the few rows that still hold guest data instead of every entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX ix_entry_guest_purge ON walkin_queue_entries (created_at) WHERE patient_profile_id IS NULL AND guest_pii_purged_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_entry_guest_purge');
    }
};
