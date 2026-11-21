<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling (increment 4.1, database-schema.md §6, architecture.md §8):
 * policies, service modes, recurring schedules with shifts and breaks,
 * exceptions, and the availability projection. Times of day are local wall
 * clock plus an IANA zone; concrete instants are TIMESTAMPTZ in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Field-by-field resolution, most specific first (service mode → doctor →
        // location → platform): a null value means "inherit". D-22 values are
        // not decided, so they stay null until Phase 6.
        Schema::create('scheduling_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->integer('min_booking_notice_minutes')->nullable();
            $table->integer('max_advance_booking_days')->nullable();
            $table->boolean('allow_same_day')->nullable();
            $table->integer('reservation_hold_minutes')->nullable();
            $table->integer('reservation_max_extension_minutes')->nullable();
            $table->integer('confirmation_hold_hours')->nullable();
            $table->integer('late_grace_minutes')->nullable();
            $table->integer('free_cancellation_hours')->nullable();
            $table->smallInteger('noshow_refund_percent')->nullable();
            $table->smallInteger('doctor_cancel_refund_percent')->nullable();
            $table->integer('availability_horizon_days')->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE scheduling_policies ADD CONSTRAINT scheduling_policies_scope_check CHECK (scope_type IN ('PLATFORM', 'CLINIC_LOCATION', 'DOCTOR', 'SERVICE_MODE') AND ((scope_type = 'PLATFORM') = (scope_id IS NULL)))");
        DB::statement('ALTER TABLE scheduling_policies ADD CONSTRAINT scheduling_policies_values_check CHECK (
            COALESCE(min_booking_notice_minutes, 0) BETWEEN 0 AND 43200
            AND COALESCE(max_advance_booking_days, 1) BETWEEN 1 AND 365
            AND COALESCE(reservation_hold_minutes, 1) BETWEEN 1 AND 60
            AND COALESCE(reservation_max_extension_minutes, 0) BETWEEN 0 AND 60
            AND COALESCE(confirmation_hold_hours, 1) BETWEEN 1 AND 168
            AND COALESCE(late_grace_minutes, 0) BETWEEN 0 AND 120
            AND COALESCE(free_cancellation_hours, 0) BETWEEN 0 AND 720
            AND COALESCE(noshow_refund_percent, 0) BETWEEN 0 AND 100
            AND COALESCE(doctor_cancel_refund_percent, 0) BETWEEN 0 AND 100
            AND COALESCE(availability_horizon_days, 1) BETWEEN 1 AND 180
            AND (effective_until IS NULL OR effective_until > effective_from))');
        DB::statement("CREATE UNIQUE INDEX scheduling_policies_scope_from ON scheduling_policies (scope_type, COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid), effective_from)");

        Schema::create('doctor_service_modes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->foreignUuid('doctor_service_id')->constrained('doctor_services')->restrictOnDelete();
            $table->string('consultation_type', 10);
            $table->string('service_mode', 12);
            $table->string('status', 10)->default('ACTIVE');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestampsTz();
            $table->index(['doctor_profile_id', 'status']);
        });
        DB::statement("ALTER TABLE doctor_service_modes ADD CONSTRAINT doctor_service_modes_values_check CHECK (consultation_type IN ('ONLINE', 'IN_PERSON') AND service_mode IN ('APPOINTMENT', 'WALK_IN', 'BOTH') AND status IN ('ACTIVE', 'INACTIVE'))");
        // §7.1: online has no location and is appointment-only; in person always has a location.
        DB::statement("ALTER TABLE doctor_service_modes ADD CONSTRAINT doctor_service_modes_online_check CHECK ((consultation_type = 'ONLINE') = (clinic_location_id IS NULL) AND (consultation_type <> 'ONLINE' OR service_mode = 'APPOINTMENT'))");
        DB::statement('ALTER TABLE doctor_service_modes ADD CONSTRAINT doctor_service_modes_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("CREATE UNIQUE INDEX doctor_service_modes_unique ON doctor_service_modes (doctor_profile_id, COALESCE(clinic_location_id, '00000000-0000-0000-0000-000000000000'::uuid), doctor_service_id, effective_from)");

        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->string('name', 120);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 10)->default('DRAFT');
            $table->integer('version')->default(1);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['doctor_profile_id', 'status']);
        });
        DB::statement("ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'ARCHIVED'))");
        DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_version_check CHECK (version >= 1)');

        Schema::create('doctor_schedule_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_schedule_id')->constrained('doctor_schedules')->cascadeOnDelete();
            $table->smallInteger('day_of_week');
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->foreignUuid('doctor_service_mode_id')->constrained('doctor_service_modes')->restrictOnDelete();
            $table->time('starts_at_local');
            $table->time('ends_at_local');
            $table->string('timezone', 64);
            $table->smallInteger('slot_duration_minutes');
            $table->smallInteger('buffer_minutes')->default(0);
            $table->smallInteger('max_appointments')->nullable();
            $table->smallInteger('walkin_capacity')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE doctor_schedule_shifts ADD CONSTRAINT doctor_schedule_shifts_values_check CHECK (
            day_of_week BETWEEN 1 AND 7
            AND ends_at_local > starts_at_local
            AND slot_duration_minutes BETWEEN 5 AND 480
            AND buffer_minutes BETWEEN 0 AND 120
            AND (max_appointments IS NULL OR max_appointments > 0)
            AND (walkin_capacity IS NULL OR walkin_capacity >= 0))');
        // No two shifts of one schedule overlap on the same weekday (overnight shifts are split in two).
        DB::statement("ALTER TABLE doctor_schedule_shifts ADD CONSTRAINT doctor_schedule_shifts_no_overlap EXCLUDE USING gist (
            doctor_schedule_id WITH =, day_of_week WITH =,
            int4range((EXTRACT(EPOCH FROM starts_at_local) / 60)::int, (EXTRACT(EPOCH FROM ends_at_local) / 60)::int, '[)') WITH &&)");

        Schema::create('doctor_schedule_breaks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_schedule_shift_id')->constrained('doctor_schedule_shifts')->cascadeOnDelete();
            $table->time('starts_at_local');
            $table->time('ends_at_local');
            $table->string('label', 80)->nullable();
            $table->timestampsTz();
        });
        // Inside the shift is validated in the service layer; the database checks the order.
        DB::statement('ALTER TABLE doctor_schedule_breaks ADD CONSTRAINT doctor_schedule_breaks_order_check CHECK (ends_at_local > starts_at_local)');

        Schema::create('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->string('exception_type', 30);
            $table->string('effect', 10);
            $table->smallInteger('priority');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('reason')->nullable();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 10)->default('ACTIVE');
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE doctor_schedule_exceptions ADD CONSTRAINT doctor_schedule_exceptions_values_check CHECK (
            exception_type IN ('VACATION', 'SICK_LEAVE', 'EMERGENCY_LEAVE', 'PUBLIC_HOLIDAY', 'CLINIC_CLOSURE', 'TEMPORARY_UNAVAILABILITY', 'SPECIAL_HOURS', 'DOCTOR_REQUESTED_CHANGE', 'CLINIC_REQUESTED_CHANGE')
            AND effect IN ('BLOCK', 'ADD_HOURS')
            AND status IN ('ACTIVE', 'CANCELLED')
            AND priority BETWEEN 1 AND 5
            AND ends_at > starts_at)");
        // D-11: special hours add time; leave, holidays, closures and unavailability only block.
        DB::statement("ALTER TABLE doctor_schedule_exceptions ADD CONSTRAINT doctor_schedule_exceptions_effect_check CHECK (
            (exception_type <> 'SPECIAL_HOURS' OR effect = 'ADD_HOURS')
            AND (effect <> 'ADD_HOURS' OR exception_type IN ('SPECIAL_HOURS', 'DOCTOR_REQUESTED_CHANGE', 'CLINIC_REQUESTED_CHANGE')))");
        DB::statement("ALTER TABLE doctor_schedule_exceptions ADD CONSTRAINT doctor_schedule_exceptions_cancel_check CHECK ((status = 'CANCELLED') = (cancelled_at IS NOT NULL))");
        DB::statement('CREATE INDEX doctor_schedule_exceptions_range ON doctor_schedule_exceptions USING gist (doctor_profile_id, tstzrange(starts_at, ends_at, \'[)\'))');

        // A rebuildable projection; never the booking authority (architecture.md §8.2).
        Schema::create('doctor_availability_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->foreignUuid('doctor_service_mode_id')->constrained('doctor_service_modes')->restrictOnDelete();
            $table->foreignUuid('doctor_schedule_shift_id')->nullable()->constrained('doctor_schedule_shifts')->nullOnDelete();
            $table->string('slot_kind', 16);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->date('local_date');
            $table->string('status', 14)->default('OPEN');
            $table->bigInteger('generation');
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['doctor_profile_id', 'starts_at', 'doctor_service_mode_id']);
            $table->index(['doctor_profile_id', 'local_date', 'status']);
            $table->index(['clinic_location_id', 'local_date']);
        });
        DB::statement("ALTER TABLE doctor_availability_slots ADD CONSTRAINT doctor_availability_slots_values_check CHECK (
            slot_kind IN ('APPOINTMENT', 'WALK_IN_WINDOW')
            AND status IN ('OPEN', 'HELD', 'BOOKED', 'BLOCKED', 'NEEDS_REVIEW')
            AND ends_at > starts_at)");
    }

    public function down(): void
    {
        foreach (['doctor_availability_slots', 'doctor_schedule_exceptions', 'doctor_schedule_breaks', 'doctor_schedule_shifts', 'doctor_schedules', 'doctor_service_modes', 'scheduling_policies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
