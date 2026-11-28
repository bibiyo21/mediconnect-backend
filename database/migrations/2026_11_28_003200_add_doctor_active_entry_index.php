<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Walk-in doctor lookup (increment 5.8-2, carry-in HAG-39). The check-out block reads a doctor's CALLED and IN_CONSULTATION entries in
 * every queue of the day (CheckOutQueues, the stale-CALLED rule of 5.8 M2). uq_one_called_entry_per_doctor covers CALLED only, so the
 * IN_CONSULTATION half was a scan. This partial index covers both statuses and is kept small: only active entries are in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX ix_entry_doctor_active ON walkin_queue_entries (assigned_doctor_profile_id) WHERE status IN ('CALLED', 'IN_CONSULTATION')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_entry_doctor_active');
    }
};
