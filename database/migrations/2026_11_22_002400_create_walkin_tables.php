<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in queues (increment 5.1, database-schema.md §8, architecture.md §10).
 * Numbers, one live entry per patient and one CALLED entry per queue are
 * database guarantees, so concurrent registrations and calls cannot break
 * them even if a service check has a bug (CLAUDE.md §28.4 scenario 2).
 */
return new class extends Migration
{
    private const NIL = "'00000000-0000-0000-0000-000000000000'::uuid";

    public function up(): void
    {
        Schema::create('walkin_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_location_id')->constrained('clinic_locations')->restrictOnDelete();
            // Null = a shared service pool (the schema allows it; the API refuses it until after Phase 6).
            $table->foreignUuid('doctor_profile_id')->nullable()->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_service_id')->nullable()->constrained('clinic_services')->restrictOnDelete();
            // The location's local calendar day.
            $table->date('local_date');
            $table->string('status', 10)->default('OPEN');
            $table->smallInteger('capacity')->nullable();
            $table->timestampTz('cutoff_at')->nullable();
            // Incremented under the queue row lock.
            $table->integer('last_number')->default(0);
            $table->string('number_prefix', 4)->nullable();
            $table->boolean('allow_pre_checkin_registration')->default(false);
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['clinic_location_id', 'local_date']);
            $table->index(['doctor_profile_id', 'local_date']);
        });
        DB::statement("ALTER TABLE walkin_queues ADD CONSTRAINT walkin_queues_values_check CHECK (
            status IN ('OPEN', 'PAUSED', 'CLOSED')
            AND (capacity IS NULL OR capacity >= 0)
            AND last_number >= 0
            AND ((status = 'CLOSED') = (closed_at IS NOT NULL))
            AND (closed_by IS NULL OR closed_at IS NOT NULL))"); // reopening a queue clears who closed it
        DB::statement('CREATE UNIQUE INDEX uq_walkin_queue_per_day ON walkin_queues (
            clinic_location_id, COALESCE(doctor_profile_id, '.self::NIL.'), COALESCE(clinic_service_id, '.self::NIL.'), local_date)');

        Schema::create('walkin_queue_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('walkin_queue_id')->constrained('walkin_queues')->restrictOnDelete();
            $table->integer('queue_number');
            // Null for an unregistered (guest) patient registered by staff.
            $table->foreignUuid('patient_profile_id')->nullable()->constrained('patient_profiles')->restrictOnDelete();
            // Application-encrypted (cast), stored as text like every *_encrypted column in this schema.
            $table->text('guest_name_encrypted')->nullable();
            $table->text('guest_phone_encrypted')->nullable();
            // HMAC blind index of the phone: lets staff see "same phone already in this queue" without decrypting.
            $table->string('guest_phone_hash', 64)->nullable();
            // Set when the retention job erases the guest's name and phone (plan Q6); the entry itself is kept.
            $table->timestampTz('guest_pii_purged_at')->nullable();
            $table->foreignUuid('registered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('registration_channel', 16);
            $table->string('status', 16);
            $table->smallInteger('triage_priority')->default(0);
            $table->foreignUuid('assigned_doctor_profile_id')->nullable()->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('payer_type', 10);
            // HMO tables arrive in Phase 7A: a plain id with no foreign key and no payer check until then (as on appointments).
            $table->uuid('hmo_membership_id')->nullable();
            $table->smallInteger('recall_count')->default(0);
            // A range, never a promise (CLAUDE.md §9.4).
            $table->timestampTz('estimated_call_at_min')->nullable();
            $table->timestampTz('estimated_call_at_max')->nullable();
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('called_at')->nullable();
            $table->timestampTz('consultation_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            // The client's key value, not a foreign key: idempotency_keys rows are purged.
            $table->string('idempotency_key', 64)->nullable();
            $table->timestampsTz();
            $table->index('patient_profile_id');
            // Lets queue events reference (entry, queue) together so an event cannot name another queue's entry.
            $table->unique(['id', 'walkin_queue_id']);
        });
        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_values_check CHECK (
            status IN ('REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION', 'COMPLETED', 'SKIPPED', 'NO_SHOW', 'CANCELLED')
            AND registration_channel IN ('PATIENT_APP', 'STAFF_CONSOLE', 'KIOSK')
            AND payer_type IN ('SELF_PAY', 'HMO')
            AND queue_number > 0
            AND triage_priority BETWEEN 0 AND 9
            AND recall_count >= 0
            AND ((estimated_call_at_min IS NULL) = (estimated_call_at_max IS NULL))
            AND (estimated_call_at_min IS NULL OR estimated_call_at_max >= estimated_call_at_min))");
        // A person is either a registered patient (no guest data) or a guest: a name, or a purge that erased it.
        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_identity_check CHECK (
            ((patient_profile_id IS NOT NULL AND guest_name_encrypted IS NULL AND guest_phone_encrypted IS NULL AND guest_phone_hash IS NULL AND guest_pii_purged_at IS NULL)
            OR (patient_profile_id IS NULL AND (guest_name_encrypted IS NOT NULL OR guest_pii_purged_at IS NOT NULL)))
            AND (guest_pii_purged_at IS NULL OR (guest_name_encrypted IS NULL AND guest_phone_encrypted IS NULL AND guest_phone_hash IS NULL)))');
        // The timestamps and the doctor a status needs (a call names the doctor; the one-CALLED indexes rely on it).
        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_coherence_check CHECK (
            (status <> 'CALLED' OR (called_at IS NOT NULL AND assigned_doctor_profile_id IS NOT NULL))
            AND (status <> 'IN_CONSULTATION' OR (consultation_started_at IS NOT NULL AND assigned_doctor_profile_id IS NOT NULL))
            AND (status <> 'COMPLETED' OR completed_at IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX uq_queue_number ON walkin_queue_entries (walkin_queue_id, queue_number)');
        DB::statement("CREATE UNIQUE INDEX uq_one_live_entry_per_patient ON walkin_queue_entries (walkin_queue_id, patient_profile_id)
            WHERE patient_profile_id IS NOT NULL AND status IN ('REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION')");
        // Q9: recall instead of calling a second patient to one queue.
        DB::statement("CREATE UNIQUE INDEX uq_one_called_entry_per_queue ON walkin_queue_entries (walkin_queue_id) WHERE status = 'CALLED'");
        // A doctor is called for one patient at a time, across queues too.
        DB::statement("CREATE UNIQUE INDEX uq_one_called_entry_per_doctor ON walkin_queue_entries (assigned_doctor_profile_id) WHERE status = 'CALLED'");
        DB::statement('CREATE INDEX ix_queue_phone ON walkin_queue_entries (walkin_queue_id, guest_phone_hash) WHERE guest_phone_hash IS NOT NULL');
        DB::statement('CREATE INDEX ix_queue_next ON walkin_queue_entries (walkin_queue_id, status, triage_priority DESC, queue_number)');

        Schema::create('walkin_queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('walkin_queue_id')->constrained('walkin_queues')->restrictOnDelete();
            // Null for queue-level events (opened, paused, closed).
            $table->foreignUuid('walkin_queue_entry_id')->nullable()->constrained('walkin_queue_entries')->restrictOnDelete();
            $table->string('event_type', 20);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            // Small and non-clinical: for example the old and new triage priority.
            $table->jsonb('metadata')->nullable();
            // clock_timestamp(), not now(): event order within one transaction stays visible.
            $table->timestampTz('occurred_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->index(['walkin_queue_id', 'occurred_at']);
            $table->index('walkin_queue_entry_id');
        });
        $statuses = "'REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION', 'COMPLETED', 'SKIPPED', 'NO_SHOW', 'CANCELLED'";
        DB::statement("ALTER TABLE walkin_queue_events ADD CONSTRAINT walkin_queue_events_values_check CHECK (
            event_type IN ('REGISTERED', 'ARRIVED', 'CALLED', 'RECALLED', 'SKIPPED', 'RETURNED', 'NO_SHOW', 'CANCELLED', 'STARTED', 'COMPLETED', 'PRIORITY_CHANGED', 'TRANSFERRED', 'QUEUE_OPENED', 'QUEUE_PAUSED', 'QUEUE_CLOSED')
            AND (from_status IS NULL OR from_status IN ({$statuses}))
            AND (to_status IS NULL OR to_status IN ({$statuses})))");
        // Queue-level events name no entry; every other event names one, and that entry belongs to this queue.
        DB::statement("ALTER TABLE walkin_queue_events ADD CONSTRAINT walkin_queue_events_entry_check CHECK (
            (event_type IN ('QUEUE_OPENED', 'QUEUE_PAUSED', 'QUEUE_CLOSED')) = (walkin_queue_entry_id IS NULL))");
        DB::statement('ALTER TABLE walkin_queue_events ADD CONSTRAINT walkin_queue_events_entry_queue_fk FOREIGN KEY (walkin_queue_entry_id, walkin_queue_id) REFERENCES walkin_queue_entries (id, walkin_queue_id)');
        $this->appendOnly('walkin_queue_events');

        // D-31: a lobby screen paired to one location by a hashed device token; it has no user account.
        Schema::create('queue_display_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinic_location_id')->constrained('clinic_locations')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('registered_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['clinic_location_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        foreach (['queue_display_devices', 'walkin_queue_events', 'walkin_queue_entries', 'walkin_queues'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function appendOnly(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION forbid_mutation()");
        $appRole = (string) config('database.connections.pgsql.username');
        if ($appRole !== '' && $appRole !== DB::connection()->getConfig('username')) {
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON '.$table.' FROM "'.str_replace('"', '""', $appRole).'"');
        }
    }
};
