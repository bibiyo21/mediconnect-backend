<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consultations and care relationships (increment 5.1, database-schema.md §10
 * and §17). A doctor runs one consultation at a time (the partial unique
 * index drives derived BUSY); a consultation has exactly one source, a booked
 * appointment or a called walk-in. A guest walk-in has no patient profile and,
 * for now, no care relationship (plan Q6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->nullable()->unique()->constrained('appointments')->restrictOnDelete();
            $table->foreignUuid('walkin_queue_entry_id')->nullable()->unique()->constrained('walkin_queue_entries')->restrictOnDelete();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            // Null only for a guest walk-in (CHECK below).
            $table->foreignUuid('patient_profile_id')->nullable()->constrained('patient_profiles')->restrictOnDelete();
            // Null = online. Denormalised from the source so clinic scope checks need no join.
            $table->foreignUuid('clinic_location_id')->nullable()->constrained('clinic_locations')->restrictOnDelete();
            $table->string('consultation_type', 10);
            $table->string('status', 16);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            // video_rooms arrive in Phase 8: a plain id with no foreign key until then.
            $table->uuid('video_room_id')->nullable();
            $table->timestampsTz();
            $table->index(['doctor_profile_id', 'status']);
            $table->index('patient_profile_id');
            $table->index(['clinic_location_id', 'status']);
        });
        DB::statement("ALTER TABLE consultations ADD CONSTRAINT consultations_values_check CHECK (
            status IN ('SCHEDULED', 'WAITING_ROOM', 'IN_PROGRESS', 'INTERRUPTED', 'COMPLETED', 'CANCELLED')
            AND consultation_type IN ('ONLINE', 'IN_PERSON')
            AND (status <> 'IN_PROGRESS' OR started_at IS NOT NULL)
            AND (status <> 'COMPLETED' OR (started_at IS NOT NULL AND ended_at IS NOT NULL AND duration_seconds IS NOT NULL))
            AND (ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at)
            AND (duration_seconds IS NULL OR duration_seconds >= 0))");
        // Exactly one source (§10).
        DB::statement('ALTER TABLE consultations ADD CONSTRAINT consultations_source_check CHECK ((appointment_id IS NOT NULL) <> (walkin_queue_entry_id IS NOT NULL))');
        // Q6: only a walk-in may have no patient profile (a guest).
        DB::statement('ALTER TABLE consultations ADD CONSTRAINT consultations_patient_check CHECK (patient_profile_id IS NOT NULL OR walkin_queue_entry_id IS NOT NULL)');
        DB::statement("ALTER TABLE consultations ADD CONSTRAINT consultations_location_check CHECK ((consultation_type = 'ONLINE') = (clinic_location_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX uq_one_in_progress_consultation ON consultations (doctor_profile_id) WHERE status = 'IN_PROGRESS'");

        // The source decides who, where and with whom; the consultation must say the same (the walk-in doctor
        // is the one assigned on the entry, else the queue's). Checked on insert and when these columns change.
        DB::unprepared("CREATE OR REPLACE FUNCTION consultations_match_source() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE v_patient uuid; v_doctor uuid; v_location uuid; v_type varchar;
            BEGIN
                IF NEW.walkin_queue_entry_id IS NOT NULL THEN
                    SELECT e.patient_profile_id, COALESCE(e.assigned_doctor_profile_id, q.doctor_profile_id), q.clinic_location_id, 'IN_PERSON'
                        INTO v_patient, v_doctor, v_location, v_type
                        FROM walkin_queue_entries e JOIN walkin_queues q ON q.id = e.walkin_queue_id WHERE e.id = NEW.walkin_queue_entry_id;
                ELSIF NEW.appointment_id IS NOT NULL THEN
                    SELECT patient_profile_id, doctor_profile_id, clinic_location_id, consultation_type
                        INTO v_patient, v_doctor, v_location, v_type FROM appointments WHERE id = NEW.appointment_id;
                ELSE
                    RETURN NEW;
                END IF;
                IF NOT FOUND OR NEW.patient_profile_id IS DISTINCT FROM v_patient OR NEW.doctor_profile_id IS DISTINCT FROM v_doctor
                    OR NEW.clinic_location_id IS DISTINCT FROM v_location OR NEW.consultation_type <> v_type THEN
                    RAISE EXCEPTION 'consultation does not match its source' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$");
        DB::statement('CREATE TRIGGER consultations_match_source BEFORE INSERT OR UPDATE OF appointment_id, walkin_queue_entry_id, patient_profile_id, doctor_profile_id, clinic_location_id, consultation_type ON consultations FOR EACH ROW EXECUTE FUNCTION consultations_match_source()');

        Schema::create('consultation_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 14);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();
            $table->unique(['consultation_id', 'user_id']);
        });
        DB::statement("ALTER TABLE consultation_participants ADD CONSTRAINT consultation_participants_values_check CHECK (
            role IN ('PATIENT', 'GUARDIAN', 'DOCTOR', 'CLINIC_STAFF', 'INTERPRETER')
            AND (left_at IS NULL OR joined_at IS NULL OR left_at >= joined_at))");

        // §20.3: who may read a patient's records. Created when an appointment is confirmed or a walk-in is called.
        Schema::create('care_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_profile_id')->constrained('doctor_profiles')->restrictOnDelete();
            $table->foreignUuid('patient_profile_id')->constrained('patient_profiles')->restrictOnDelete();
            $table->string('source_type', 12);
            // The appointment or queue entry id (polymorphic, so no foreign key).
            $table->uuid('source_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('access_until');
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['source_type', 'source_id']);
            $table->index(['doctor_profile_id', 'patient_profile_id', 'status']);
            $table->index('patient_profile_id');
        });
        DB::statement("ALTER TABLE care_relationships ADD CONSTRAINT care_relationships_values_check CHECK (
            source_type IN ('APPOINTMENT', 'WALK_IN')
            AND status IN ('ACTIVE', 'EXPIRED', 'REVOKED')
            AND access_until > starts_at
            AND ((status = 'REVOKED') = (revoked_at IS NOT NULL)))");
        // The source must exist and be about this patient and this doctor (the same walk-in doctor rule as consultations).
        DB::unprepared("CREATE OR REPLACE FUNCTION care_relationships_match_source() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE v_patient uuid; v_doctor uuid;
            BEGIN
                IF NEW.source_type = 'WALK_IN' THEN
                    SELECT e.patient_profile_id, COALESCE(e.assigned_doctor_profile_id, q.doctor_profile_id) INTO v_patient, v_doctor
                        FROM walkin_queue_entries e JOIN walkin_queues q ON q.id = e.walkin_queue_id WHERE e.id = NEW.source_id;
                ELSE
                    SELECT patient_profile_id, doctor_profile_id INTO v_patient, v_doctor FROM appointments WHERE id = NEW.source_id;
                END IF;
                IF NOT FOUND OR NEW.patient_profile_id IS DISTINCT FROM v_patient OR NEW.doctor_profile_id IS DISTINCT FROM v_doctor THEN
                    RAISE EXCEPTION 'care relationship does not match its source' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$");
        DB::statement('CREATE TRIGGER care_relationships_match_source BEFORE INSERT OR UPDATE OF source_type, source_id, patient_profile_id, doctor_profile_id ON care_relationships FOR EACH ROW EXECUTE FUNCTION care_relationships_match_source()');
    }

    public function down(): void
    {
        foreach (['care_relationships', 'consultation_participants', 'consultations'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS care_relationships_match_source()');
        DB::statement('DROP FUNCTION IF EXISTS consultations_match_source()');
    }
};
