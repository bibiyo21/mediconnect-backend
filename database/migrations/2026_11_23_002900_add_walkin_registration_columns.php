<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in registration columns and constraints (increment 5.5a-1, decisions 51 to 103).
 * The 5.1 migration is committed, so its CHECKs are replaced here, not edited.
 *
 * - doctor_service_id: the service the patient registered for (NOT NULL; registration did not exist
 *   before this increment, so the migration refuses to run over existing rows).
 * - triage: three levels (0 normal, 5 priority, 9 urgent). A reason code exists exactly when the level
 *   is above 0; the free-text note is encrypted, only allowed with a level above 0, never copied anywhere.
 * - privacy notice (A5): version, who showed it and when, all or none, and always for a guest.
 * - hmo_card_name_encrypted: what the patient says their HMO is called (application-encrypted, at most 120
 *   characters before encryption; the API field stays hmo_card_name_text); unverified, HMO payers only.
 *   Deviation from decision 103, which named a plain hmo_card_name_text column.
 * - The entry's doctor service must be an in-person service of the queue's own doctor, location and clinic
 *   service (a trigger, like consultations_match_source); a shared-pool queue (no doctor) takes no entries yet.
 * - A PATIENT_APP entry has a patient profile (the reverse is an application rule).
 * - DOCTOR_APP is accepted as a registration channel but nothing writes it until the owner approves A2.
 * - WAITING means the patient has arrived, so it needs arrived_at; REGISTERED means not arrived, so it has none.
 * - The guest purge also clears the card name and the note.
 * - Per-registrant idempotency, a remote-registration count index, and one prefix per location and day.
 * - CAPACITY_OVERRIDDEN is a recordable event (B9).
 * walkin_queues.allow_pre_checkin_registration stays as reserved and unused (B2 supersedes it).
 *
 * Irreversible once it holds data: down() refuses when any entry exists (an event always names an entry, and events
 * are append-only), because dropping the columns would erase the registration record.
 */
return new class extends Migration
{
    private const STATUSES = "'REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION', 'COMPLETED', 'SKIPPED', 'NO_SHOW', 'CANCELLED'";

    private const REASONS = "'SENIOR_PWD', 'PREGNANT', 'DOCTOR_REQUEST', 'STAFF_CONCERN', 'OTHER'";

    public function up(): void
    {
        if (DB::table('walkin_queue_entries')->exists()) {
            throw new RuntimeException('walkin_queue_entries has rows: doctor_service_id cannot be added NOT NULL. Registration did not exist before this migration, so this is unexpected.');
        }

        Schema::table('walkin_queue_entries', function (Blueprint $table) {
            // Which of the doctor's services the registrant chose. The queue is keyed by the clinic service; two
            // doctor services of one clinic service share a queue and each entry keeps its own.
            $table->foreignUuid('doctor_service_id')->constrained('doctor_services')->restrictOnDelete();
            $table->string('triage_reason_code', 20)->nullable();
            // Application-encrypted (cast). At most 140 characters before encryption (application rule).
            $table->text('triage_note_encrypted')->nullable();
            $table->string('privacy_notice_version', 16)->nullable();
            $table->foreignUuid('privacy_notice_shown_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('privacy_notice_shown_at')->nullable();
            // As typed by the patient, never verified: shown as "Patient says HMO: not verified".
            // Application-encrypted (cast), like the guest name. At most 120 characters before encryption (application rule).
            $table->text('hmo_card_name_encrypted')->nullable();
            $table->index('doctor_service_id');
            $table->index('privacy_notice_shown_by_user_id');
        });

        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_values_check');
        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_values_check CHECK (
            status IN ('.self::STATUSES.")
            AND registration_channel IN ('PATIENT_APP', 'STAFF_CONSOLE', 'KIOSK', 'DOCTOR_APP')
            AND payer_type IN ('SELF_PAY', 'HMO')
            AND queue_number > 0
            AND triage_priority IN (0, 5, 9)
            AND recall_count >= 0
            AND ((estimated_call_at_min IS NULL) = (estimated_call_at_max IS NULL))
            AND (estimated_call_at_min IS NULL OR estimated_call_at_max >= estimated_call_at_min))");

        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_triage_check CHECK (
            ((triage_priority > 0) = (triage_reason_code IS NOT NULL))
            AND (triage_reason_code IS NULL OR triage_reason_code IN ('.self::REASONS.'))
            AND (triage_note_encrypted IS NULL OR triage_priority > 0))');

        // The notice is shown to a guest at the desk; its trio is kept even after the guest's data is purged.
        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_privacy_notice_check CHECK (
            ((privacy_notice_version IS NULL) = (privacy_notice_shown_by_user_id IS NULL))
            AND ((privacy_notice_version IS NULL) = (privacy_notice_shown_at IS NULL))
            AND (patient_profile_id IS NOT NULL OR privacy_notice_version IS NOT NULL))');

        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_hmo_text_check CHECK (
            hmo_card_name_encrypted IS NULL OR payer_type = 'HMO')");

        // The patient app registers a patient, never a guest. (A staff entry for a patient is allowed; that stays an application rule.)
        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_channel_check CHECK (
            registration_channel <> 'PATIENT_APP' OR patient_profile_id IS NOT NULL)");

        // The service an entry was registered for belongs to the queue's own doctor, location and clinic service and is
        // in person. A plain = on the doctor means a shared-pool queue (no doctor) matches nothing, as the API refuses it.
        DB::unprepared("CREATE OR REPLACE FUNCTION walkin_queue_entries_match_service() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE v_queue record; v_service record;
            BEGIN
                -- A missing or unknown reference is answered by NOT NULL and the foreign keys, not here.
                IF NEW.doctor_service_id IS NULL OR NEW.walkin_queue_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT clinic_location_id, doctor_profile_id, clinic_service_id INTO v_queue FROM walkin_queues WHERE id = NEW.walkin_queue_id;
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;
                SELECT clinic_location_id, doctor_profile_id, clinic_service_id, consultation_type INTO v_service FROM doctor_services WHERE id = NEW.doctor_service_id;
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;
                IF NOT COALESCE(v_service.clinic_location_id = v_queue.clinic_location_id
                    AND v_service.doctor_profile_id = v_queue.doctor_profile_id
                    AND v_service.clinic_service_id IS NOT DISTINCT FROM v_queue.clinic_service_id
                    AND v_service.consultation_type = 'IN_PERSON', false) THEN
                    RAISE EXCEPTION 'walk-in entry does not match its queue' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$");
        DB::statement('CREATE TRIGGER walkin_queue_entries_match_service BEFORE INSERT OR UPDATE OF doctor_service_id, walkin_queue_id ON walkin_queue_entries FOR EACH ROW EXECUTE FUNCTION walkin_queue_entries_match_service()');

        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_identity_check');
        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_identity_check CHECK (
            ((patient_profile_id IS NOT NULL AND guest_name_encrypted IS NULL AND guest_phone_encrypted IS NULL AND guest_phone_hash IS NULL AND guest_pii_purged_at IS NULL)
            OR (patient_profile_id IS NULL AND (guest_name_encrypted IS NOT NULL OR guest_pii_purged_at IS NOT NULL)))
            AND (guest_pii_purged_at IS NULL OR (guest_name_encrypted IS NULL AND guest_phone_encrypted IS NULL AND guest_phone_hash IS NULL
                AND hmo_card_name_encrypted IS NULL AND triage_note_encrypted IS NULL)))');

        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_coherence_check');
        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_coherence_check CHECK (
            (status <> 'WAITING' OR arrived_at IS NOT NULL)
            AND (status <> 'REGISTERED' OR arrived_at IS NULL)
            AND (status <> 'CALLED' OR (called_at IS NOT NULL AND assigned_doctor_profile_id IS NOT NULL))
            AND (status <> 'IN_CONSULTATION' OR (consultation_started_at IS NOT NULL AND assigned_doctor_profile_id IS NOT NULL))
            AND (status <> 'COMPLETED' OR completed_at IS NOT NULL))");

        // A retry after a crash (the idempotency_keys row may be gone) finds the entry instead of making a second one.
        DB::statement('CREATE UNIQUE INDEX uq_entry_registrant_idempotency ON walkin_queue_entries (registered_by_user_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        // The daily limits of remote registration (A7) count one account's patient-app entries; the day comes from a join to the queue.
        DB::statement("CREATE INDEX ix_entry_remote_registrant ON walkin_queue_entries (registered_by_user_id, created_at) WHERE registration_channel = 'PATIENT_APP'");

        // Two queues of one location and day never share a prefix (B4). Queues without a prefix are unconstrained.
        DB::statement('CREATE UNIQUE INDEX uq_walkin_queue_prefix ON walkin_queues (clinic_location_id, local_date, number_prefix) WHERE number_prefix IS NOT NULL');

        DB::statement('ALTER TABLE walkin_queue_events DROP CONSTRAINT walkin_queue_events_values_check');
        DB::statement('ALTER TABLE walkin_queue_events ADD CONSTRAINT walkin_queue_events_values_check CHECK (
            event_type IN (\'REGISTERED\', \'ARRIVED\', \'CALLED\', \'RECALLED\', \'SKIPPED\', \'RETURNED\', \'NO_SHOW\', \'CANCELLED\', \'STARTED\', \'COMPLETED\', \'PRIORITY_CHANGED\', \'TRANSFERRED\', \'CAPACITY_OVERRIDDEN\', \'QUEUE_OPENED\', \'QUEUE_PAUSED\', \'QUEUE_CLOSED\')
            AND (from_status IS NULL OR from_status IN ('.self::STATUSES.'))
            AND (to_status IS NULL OR to_status IN ('.self::STATUSES.')))');
    }

    public function down(): void
    {
        if (DB::table('walkin_queue_entries')->exists()) {
            throw new RuntimeException('Refusing to roll back: walk-in entries exist (and so do their events, which cannot be removed), and dropping the registration columns would erase them. This migration is irreversible once it holds data.');
        }

        DB::statement('ALTER TABLE walkin_queue_events DROP CONSTRAINT walkin_queue_events_values_check');
        DB::statement('ALTER TABLE walkin_queue_events ADD CONSTRAINT walkin_queue_events_values_check CHECK (
            event_type IN (\'REGISTERED\', \'ARRIVED\', \'CALLED\', \'RECALLED\', \'SKIPPED\', \'RETURNED\', \'NO_SHOW\', \'CANCELLED\', \'STARTED\', \'COMPLETED\', \'PRIORITY_CHANGED\', \'TRANSFERRED\', \'QUEUE_OPENED\', \'QUEUE_PAUSED\', \'QUEUE_CLOSED\')
            AND (from_status IS NULL OR from_status IN ('.self::STATUSES.'))
            AND (to_status IS NULL OR to_status IN ('.self::STATUSES.')))');

        DB::statement('DROP INDEX IF EXISTS uq_walkin_queue_prefix');
        DB::statement('DROP INDEX IF EXISTS ix_entry_remote_registrant');
        DB::statement('DROP INDEX IF EXISTS uq_entry_registrant_idempotency');

        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_coherence_check');
        DB::statement("ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_coherence_check CHECK (
            (status <> 'CALLED' OR (called_at IS NOT NULL AND assigned_doctor_profile_id IS NOT NULL))
            AND (status <> 'IN_CONSULTATION' OR (consultation_started_at IS NOT NULL AND assigned_doctor_profile_id IS NOT NULL))
            AND (status <> 'COMPLETED' OR completed_at IS NOT NULL))");

        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_identity_check');
        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_identity_check CHECK (
            ((patient_profile_id IS NOT NULL AND guest_name_encrypted IS NULL AND guest_phone_encrypted IS NULL AND guest_phone_hash IS NULL AND guest_pii_purged_at IS NULL)
            OR (patient_profile_id IS NULL AND (guest_name_encrypted IS NOT NULL OR guest_pii_purged_at IS NOT NULL)))
            AND (guest_pii_purged_at IS NULL OR (guest_name_encrypted IS NULL AND guest_phone_encrypted IS NULL AND guest_phone_hash IS NULL)))');

        DB::statement('DROP TRIGGER IF EXISTS walkin_queue_entries_match_service ON walkin_queue_entries');
        DB::statement('DROP FUNCTION IF EXISTS walkin_queue_entries_match_service()');
        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_channel_check');
        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_hmo_text_check');
        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_privacy_notice_check');
        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_triage_check');

        DB::statement('ALTER TABLE walkin_queue_entries DROP CONSTRAINT walkin_queue_entries_values_check');
        DB::statement('ALTER TABLE walkin_queue_entries ADD CONSTRAINT walkin_queue_entries_values_check CHECK (
            status IN ('.self::STATUSES.")
            AND registration_channel IN ('PATIENT_APP', 'STAFF_CONSOLE', 'KIOSK')
            AND payer_type IN ('SELF_PAY', 'HMO')
            AND queue_number > 0
            AND triage_priority BETWEEN 0 AND 9
            AND recall_count >= 0
            AND ((estimated_call_at_min IS NULL) = (estimated_call_at_max IS NULL))
            AND (estimated_call_at_min IS NULL OR estimated_call_at_max >= estimated_call_at_min))");

        Schema::table('walkin_queue_entries', function (Blueprint $table) {
            $table->dropIndex(['doctor_service_id']);
            $table->dropIndex(['privacy_notice_shown_by_user_id']);
            $table->dropConstrainedForeignId('privacy_notice_shown_by_user_id');
            $table->dropConstrainedForeignId('doctor_service_id');
            $table->dropColumn(['triage_reason_code', 'triage_note_encrypted', 'privacy_notice_version', 'privacy_notice_shown_at', 'hmo_card_name_encrypted']);
        });
    }
};
