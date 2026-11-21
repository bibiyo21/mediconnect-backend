<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments and holds (increment 4.1, database-schema.md §6 and §9,
 * architecture.md §8.3). Appointments are structure only until Phase 6, but
 * the overlap constraint exists from day one so holds and bookings are
 * protected by the same guarantee: no two live bookings of one doctor
 * overlap, across every location and online (§7.5, concurrency scenario 11).
 */
return new class extends Migration
{
    private const LIVE = "'PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'INTERRUPTED'";

    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_code', 12)->unique();
            $table->foreignUuid('patient_profile_id')->constrained('patient_profiles')->restrictOnDelete();
            $table->foreignUuid('booked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->foreignUuid('doctor_service_id')->constrained('doctor_services')->restrictOnDelete();
            $table->foreignUuid('doctor_service_mode_id')->constrained('doctor_service_modes')->restrictOnDelete();
            // The projection row may be rebuilt; the appointment keeps its own times.
            $table->foreignUuid('doctor_availability_slot_id')->nullable()->constrained('doctor_availability_slots')->nullOnDelete();
            $table->string('consultation_type', 10);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone', 64);
            $table->string('status', 24);
            $table->string('payer_type', 10);
            // HMO tables arrive in Phase 7A; the foreign keys are added then.
            $table->uuid('hmo_membership_id')->nullable();
            $table->uuid('hmo_coverage_check_id')->nullable();
            $table->bigInteger('fee_amount');
            $table->char('currency', 3)->default('PHP');
            $table->jsonb('policy_snapshot')->nullable();
            $table->string('refund_eligibility', 10)->nullable();
            $table->string('cancelled_by_party', 10)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->boolean('needs_rescheduling')->default(false);
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('rescheduled_from_starts_at')->nullable();
            $table->smallInteger('reschedule_count')->default(0);
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->index(['patient_profile_id', 'starts_at']);
            $table->index(['doctor_profile_id', 'starts_at']);
            $table->index(['clinic_location_id', 'starts_at']);
        });
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_values_check CHECK (
            status IN ('PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'EXPIRED', 'NO_SHOW', 'INTERRUPTED')
            AND consultation_type IN ('ONLINE', 'IN_PERSON')
            AND payer_type IN ('SELF_PAY', 'HMO')
            AND (refund_eligibility IS NULL OR refund_eligibility IN ('FULL', 'PARTIAL', 'NONE'))
            AND (cancelled_by_party IS NULL OR cancelled_by_party IN ('PATIENT', 'DOCTOR', 'CLINIC', 'SYSTEM'))
            AND ends_at > starts_at
            AND fee_amount >= 0 AND currency ~ '^[A-Z]{3}$'
            AND reschedule_count >= 0 AND version >= 1)");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_payer_check CHECK ((payer_type = 'HMO') = (hmo_membership_id IS NOT NULL))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_location_check CHECK ((consultation_type = 'ONLINE') = (clinic_location_id IS NULL))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_doctor_no_overlap EXCLUDE USING gist (
            doctor_profile_id WITH =, tstzrange(starts_at, ends_at, \'[)\') WITH &&) WHERE (status IN ('.self::LIVE.'))');

        Schema::create('appointment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 10);
            $table->text('reason')->nullable();
            $table->timestampTz('old_starts_at')->nullable();
            $table->timestampTz('new_starts_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['appointment_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE appointment_status_history ADD CONSTRAINT appointment_status_history_actor_check CHECK (actor_type IN ('PATIENT', 'DOCTOR', 'STAFF', 'ADMIN', 'SYSTEM', 'GATEWAY'))");
        $this->appendOnly('appointment_status_history');

        Schema::create('appointment_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('participant_role', 14);
            $table->foreignUuid('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['appointment_id', 'user_id']);
        });
        DB::statement("ALTER TABLE appointment_participants ADD CONSTRAINT appointment_participants_role_check CHECK (participant_role IN ('PATIENT', 'GUARDIAN', 'DOCTOR', 'INTERPRETER', 'CLINIC_STAFF'))");

        Schema::create('appointment_slot_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Kept while ACTIVE (the generator never drops a held slot); cleared if a finished hold's slot is rebuilt.
            $table->foreignUuid('doctor_availability_slot_id')->nullable()->constrained('doctor_availability_slots')->nullOnDelete();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignUuid('patient_profile_id')->constrained('patient_profiles')->restrictOnDelete();
            $table->foreignUuid('booked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampTz('expires_at');
            $table->smallInteger('extended_count')->default(0);
            $table->string('idempotency_key', 64)->nullable();
            $table->timestampsTz();
            $table->index('patient_profile_id');
        });
        DB::statement("ALTER TABLE appointment_slot_reservations ADD CONSTRAINT appointment_slot_reservations_values_check CHECK (
            status IN ('ACTIVE', 'CONVERTED', 'EXPIRED', 'RELEASED')
            AND ends_at > starts_at
            AND extended_count BETWEEN 0 AND 1
            AND (status <> 'ACTIVE' OR doctor_availability_slot_id IS NOT NULL)
            AND (status <> 'CONVERTED' OR appointment_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX uq_active_hold_per_slot ON appointment_slot_reservations (doctor_availability_slot_id) WHERE status = 'ACTIVE'");
        DB::statement("ALTER TABLE appointment_slot_reservations ADD CONSTRAINT no_overlapping_active_holds EXCLUDE USING gist (
            doctor_profile_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'ACTIVE')");
        DB::statement("CREATE INDEX ix_holds_expiring ON appointment_slot_reservations (expires_at) WHERE status = 'ACTIVE'");

        // §8.3 workflow: appointments affected by a schedule change, exception or check-out (worked in Phase 6).
        Schema::create('rescheduling_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->string('reason', 20);
            $table->string('status', 12)->default('OPEN');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE rescheduling_tasks ADD CONSTRAINT rescheduling_tasks_values_check CHECK (
            reason IN ('DOCTOR_CHECKOUT', 'SCHEDULE_CHANGE', 'EXCEPTION_ADDED')
            AND status IN ('OPEN', 'IN_PROGRESS', 'RESOLVED')
            AND ((status = 'RESOLVED') = (resolved_at IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX rescheduling_tasks_one_open ON rescheduling_tasks (appointment_id) WHERE status <> 'RESOLVED'");
    }

    public function down(): void
    {
        foreach (['rescheduling_tasks', 'appointment_slot_reservations', 'appointment_participants', 'appointment_status_history', 'appointments'] as $t) {
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
