<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queue operations schema (increment 5.6-1, decisions 102 and D11 of the 5.6 plan). The earlier migrations are committed, so
 * the index is replaced here, not edited.
 *
 * - queue_sort_at: the call-order key (B5). Set when the patient arrives (or is registered as arrived) and set again to "now"
 *   when a skipped patient is returned, so a returned entry sorts by its return time while arrived_at keeps the true arrival.
 *   Every WAITING entry has one; a REGISTERED entry has none.
 * - ix_queue_next serves call-next: WAITING entries in (triage DESC, queue_sort_at, queue_number) order.
 * - uq_one_held_entry_per_patient: a patient holds at most one place per queue, SKIPPED included (decision 94). The partial
 *   index uq_one_live_entry_per_patient stays; NO_SHOW is not held, so a no-show patient may register again.
 *
 * down() refuses when an entry's sort key differs from its arrival (a returned entry): dropping the column would lose the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walkin_queue_entries', function (Blueprint $table) {
            $table->timestampTz('queue_sort_at', 6)->nullable();
        });
        DB::statement('UPDATE walkin_queue_entries SET queue_sort_at = arrived_at WHERE arrived_at IS NOT NULL');

        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_sort_check CHECK (
            (status <> 'WAITING' OR queue_sort_at IS NOT NULL)
            AND (status <> 'REGISTERED' OR queue_sort_at IS NULL))");

        DB::statement('DROP INDEX IF EXISTS ix_queue_next');
        DB::statement("CREATE INDEX ix_queue_next ON walkin_queue_entries (walkin_queue_id, triage_priority DESC, queue_sort_at, queue_number) WHERE status = 'WAITING'");

        DB::statement("CREATE UNIQUE INDEX uq_one_held_entry_per_patient ON walkin_queue_entries (walkin_queue_id, patient_profile_id)
            WHERE patient_profile_id IS NOT NULL AND status IN ('REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION', 'SKIPPED')");
    }

    public function down(): void
    {
        if (DB::table('walkin_queue_entries')->whereNotNull('queue_sort_at')->whereRaw('queue_sort_at IS DISTINCT FROM arrived_at')->exists()) {
            throw new RuntimeException('Refusing to roll back: an entry has a call-order key that differs from its arrival (a returned entry), and dropping queue_sort_at would lose that order.');
        }

        DB::statement('DROP INDEX IF EXISTS uq_one_held_entry_per_patient');
        DB::statement('DROP INDEX IF EXISTS ix_queue_next');
        DB::statement('CREATE INDEX ix_queue_next ON walkin_queue_entries (walkin_queue_id, status, triage_priority DESC, queue_number)');
        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT IF EXISTS walkin_queue_entries_sort_check');

        Schema::table('walkin_queue_entries', function (Blueprint $table) {
            $table->dropColumn('queue_sort_at');
        });
    }
};
