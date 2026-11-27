<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\DTOs\WalkInCareGrant;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Exceptions\ConcurrentConsultationChange;
use App\Modules\Consultations\Exceptions\ConsultationRefused;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\Contracts\PresenceVisibility;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Contracts\WalkInConsultationEntries;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Shared\Contracts\Clock;
use App\Shared\Database\LockTimeoutTransaction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts, ends, interrupts and resumes a walk-in consultation (increment 5.7, brief D-A to D-G). Lock order, fixed for every path (doctor-presence.md):
 * presence session FOR UPDATE, then the walk-in queue, then its entry (both through WalkInConsultationEntries), then the consultation,
 * then care_relationships (start only, as the last write). A refusal rolls everything back, so no care row survives it.
 *
 * Start refuses unless the doctor is stored IN at this location. End never refuses on presence: a doctor must always be able to finish.
 * Both are idempotent by state: a repeat of the move that already happened returns the consultation with no write.
 */
final class ConsultationLifecycle
{
    public function __construct(
        private readonly PresenceAdmission $presence,
        private readonly WalkInConsultationEntries $entries,
        private readonly CareRelationships $care,
        private readonly ActiveConsultations $active,
        private readonly RecordsAudit $audit,
        private readonly PresenceVisibility $visibility,
        private readonly Clock $clock,
    ) {}

    /** Starts the consultation (SCHEDULED or WAITING_ROOM to IN_PROGRESS). Returns the consultation as it stands. */
    public function start(User $actor, Consultation $consultation): Consultation
    {
        $entryId = $this->entryOf($consultation);

        return $this->locked(fn () => $this->startLocked($actor, $consultation, $entryId));
    }

    /** Ends the consultation (IN_PROGRESS or INTERRUPTED to COMPLETED), with the duration in whole seconds. The care relationship is kept. */
    public function end(User $actor, Consultation $consultation): Consultation
    {
        $entryId = $this->entryOf($consultation);

        return $this->locked(fn () => $this->endLocked($actor, $consultation, $entryId));
    }

    private function startLocked(User $actor, Consultation $outer, string $entryId): Consultation
    {
        $doctorId = $outer->doctor_profile_id;
        $locationId = (string) $outer->clinic_location_id;

        $presence = $this->presence->lockExclusive($doctorId, $locationId);
        if ($presence->status !== PresenceStatus::In) {
            throw ConsultationRefused::notAvailable(match ($presence->status) {
                PresenceStatus::OnBreak => 'on_break',
                PresenceStatus::Out => 'checked_out',
                PresenceStatus::NotCheckedIn => 'not_checked_in',
                default => 'unavailable',
            });
        }

        $facts = $this->entries->lockEntry($entryId);
        $consultation = Consultation::query()->whereKey($outer->id)->lockForUpdate()->firstOrFail();
        if ($consultation->status === ConsultationStatus::InProgress) {
            return $consultation; // the retry of a start that already happened: no write, no event, no grant
        }
        if (! in_array($consultation->status, [ConsultationStatus::Scheduled, ConsultationStatus::WaitingRoom], true)) {
            throw ConsultationRefused::invalidTransition($consultation->status->value);
        }
        if ($facts->status !== EntryStatus::Called || $facts->doctorProfileId !== $doctorId) {
            throw ConsultationRefused::invalidTransition($facts->status->value);
        }
        if ($facts->queueClosed) {
            throw ConsultationRefused::queueClosed('closed');
        }
        if (! $facts->queueIsToday) {
            throw ConsultationRefused::queueClosed('not_today');
        }
        if (! $facts->assignmentActive) {
            throw ConsultationRefused::notAvailable('no_assignment');
        }
        // M-1: a de-verified doctor or a suspended location keeps a stored IN presence, so it is checked here, not only at call time.
        if (! $facts->doctorActive) {
            throw ConsultationRefused::notAvailable('doctor_not_active');
        }
        if (! $facts->locationActive) {
            throw ConsultationRefused::notAvailable('location_not_active');
        }
        if ($this->active->inProgressFor($doctorId) !== null) {
            throw ConsultationRefused::activeConsultation();
        }

        $now = $this->clock->now();
        $consultation->forceFill(['status' => ConsultationStatus::InProgress, 'started_at' => $now])->save();
        $this->entries->markInConsultation($facts->entryId, $actor->id, $now);
        $this->join($consultation, $now, $doctorId, $facts->patientProfileId);

        // Last domain write (D-A): a refusal or rollback above leaves no care relationship behind. The audit row follows the grant so it can name it (L-5).
        $careId = $this->care->grantForWalkIn(new WalkInCareGrant($facts->entryId, $doctorId, $facts->patientProfileId, $facts->timezone, $now));
        $this->audit->record('consultations.started', 'consultation', $consultation->id, [
            'walkin_queue_entry_id' => $facts->entryId, 'clinic_location_id' => $locationId, 'is_guest' => $facts->patientProfileId === null,
            'care_relationship_id' => $careId,
        ], clinicId: $this->clinicOf($locationId));
        DB::afterCommit(fn () => $this->visibility->forgetPublic($doctorId));

        return $consultation;
    }

    private function endLocked(User $actor, Consultation $outer, string $entryId): Consultation
    {
        $doctorId = $outer->doctor_profile_id;
        $locationId = (string) $outer->clinic_location_id;

        // Taken for the lock order only: an end never refuses on presence (a doctor must always be able to finish).
        $this->presence->lockExclusive($doctorId, $locationId);
        $facts = $this->entries->lockEntry($entryId);
        $consultation = Consultation::query()->whereKey($outer->id)->lockForUpdate()->firstOrFail();
        if ($consultation->status === ConsultationStatus::Completed) {
            return $consultation; // the retry of an end that already happened
        }
        if (! in_array($consultation->status, [ConsultationStatus::InProgress, ConsultationStatus::Interrupted], true)) {
            throw ConsultationRefused::invalidTransition($consultation->status->value);
        }
        if ($facts->status !== EntryStatus::InConsultation) {
            throw ConsultationRefused::invalidTransition($facts->status->value);
        }

        $now = $this->clock->now();
        $startedAt = $consultation->started_at ?? $now;
        $duration = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
        $consultation->forceFill(['status' => ConsultationStatus::Completed, 'ended_at' => $now, 'duration_seconds' => $duration])->save();
        $this->entries->markCompleted($facts->entryId, $actor->id, $now);
        DB::table('consultation_participants')->where('consultation_id', $consultation->id)->whereNull('left_at')
            ->update(['left_at' => $now, 'updated_at' => $now]);
        $this->audit->record('consultations.ended', 'consultation', $consultation->id, [
            'walkin_queue_entry_id' => $facts->entryId, 'clinic_location_id' => $locationId, 'duration_seconds' => $duration,
        ], clinicId: $this->clinicOf($locationId));
        DB::afterCommit(fn () => $this->visibility->forgetPublic($doctorId));

        return $consultation;
    }

    /**
     * Interrupts a running consultation (IN_PROGRESS to INTERRUPTED, brief D-G). Never refuses on presence: a doctor must be able to pause
     * a consultation whatever the stored state. The entry stays IN_CONSULTATION, the grant is untouched and the doctor is not BUSY while it is
     * interrupted. A repeat of an interrupt that already happened returns the consultation with no write.
     */
    public function interrupt(Consultation $consultation): Consultation
    {
        $entryId = $this->entryOf($consultation);

        return $this->locked(fn () => $this->interruptLocked($consultation, $entryId));
    }

    /**
     * Resumes an interrupted consultation (INTERRUPTED to IN_PROGRESS, D-G). Needs the doctor stored IN at this location and no other
     * IN_PROGRESS consultation of the doctor. A repeat of a resume that already happened returns the consultation with no write.
     */
    public function resume(Consultation $consultation): Consultation
    {
        $entryId = $this->entryOf($consultation);

        return $this->locked(fn () => $this->resumeLocked($consultation, $entryId));
    }

    private function interruptLocked(Consultation $outer, string $entryId): Consultation
    {
        $doctorId = $outer->doctor_profile_id;
        $locationId = (string) $outer->clinic_location_id;

        $this->presence->lockExclusive($doctorId, $locationId);
        $facts = $this->entries->lockEntry($entryId);
        $consultation = Consultation::query()->whereKey($outer->id)->lockForUpdate()->firstOrFail();
        if ($consultation->status === ConsultationStatus::Interrupted) {
            return $consultation;
        }
        if ($consultation->status !== ConsultationStatus::InProgress) {
            throw ConsultationRefused::invalidTransition($consultation->status->value);
        }
        if ($facts->status !== EntryStatus::InConsultation) {
            throw ConsultationRefused::invalidTransition($facts->status->value);
        }

        $consultation->forceFill(['status' => ConsultationStatus::Interrupted])->save();
        $this->audit->record('consultations.interrupted', 'consultation', $consultation->id, [
            'walkin_queue_entry_id' => $facts->entryId, 'clinic_location_id' => $locationId,
        ], clinicId: $this->clinicOf($locationId));
        DB::afterCommit(fn () => $this->visibility->forgetPublic($doctorId));

        return $consultation;
    }

    private function resumeLocked(Consultation $outer, string $entryId): Consultation
    {
        $doctorId = $outer->doctor_profile_id;
        $locationId = (string) $outer->clinic_location_id;

        $presence = $this->presence->lockExclusive($doctorId, $locationId);
        $facts = $this->entries->lockEntry($entryId);
        $consultation = Consultation::query()->whereKey($outer->id)->lockForUpdate()->firstOrFail();
        if ($consultation->status === ConsultationStatus::InProgress) {
            return $consultation;
        }
        if ($consultation->status !== ConsultationStatus::Interrupted) {
            throw ConsultationRefused::invalidTransition($consultation->status->value);
        }
        if ($presence->status !== PresenceStatus::In) {
            throw ConsultationRefused::notAvailable(match ($presence->status) {
                PresenceStatus::OnBreak => 'on_break',
                PresenceStatus::Out => 'checked_out',
                PresenceStatus::NotCheckedIn => 'not_checked_in',
                default => 'unavailable',
            });
        }
        if ($facts->status !== EntryStatus::InConsultation) {
            throw ConsultationRefused::invalidTransition($facts->status->value);
        }
        // m3 / L-1: resume repeats start's checks, so a consultation interrupted before a de-verification, a suspension, an
        // unassignment or the end of the queue's day cannot be resumed by a stale stored IN presence.
        if (! $facts->queueIsToday) {
            throw ConsultationRefused::queueClosed('not_today');
        }
        if (! $facts->assignmentActive) {
            throw ConsultationRefused::notAvailable('no_assignment');
        }
        if (! $facts->doctorActive) {
            throw ConsultationRefused::notAvailable('doctor_not_active');
        }
        if (! $facts->locationActive) {
            throw ConsultationRefused::notAvailable('location_not_active');
        }
        if ($this->active->inProgressFor($doctorId) !== null) {
            throw ConsultationRefused::activeConsultation();
        }

        $consultation->forceFill(['status' => ConsultationStatus::InProgress])->save();
        $this->audit->record('consultations.resumed', 'consultation', $consultation->id, [
            'walkin_queue_entry_id' => $facts->entryId, 'clinic_location_id' => $locationId,
        ], clinicId: $this->clinicOf($locationId));
        DB::afterCommit(fn () => $this->visibility->forgetPublic($doctorId));

        return $consultation;
    }

    /** The doctor (always) and the patient (their own account, when they have one) join at the start. Guardians and staff are not added in 5.7. */
    private function join(Consultation $consultation, CarbonImmutable $at, string $doctorId, ?string $patientId): void
    {
        $rows = [['role' => 'DOCTOR', 'user' => DoctorProfile::query()->whereKey($doctorId)->value('user_id')]];
        if ($patientId !== null) {
            $rows[] = ['role' => 'PATIENT', 'user' => PatientProfile::query()->whereKey($patientId)->value('user_id')];
        }
        foreach ($rows as $row) {
            if (! is_string($row['user'])) {
                continue;
            }
            DB::table('consultation_participants')->insert([
                'id' => (string) Str::uuid7(), 'consultation_id' => $consultation->id, 'user_id' => $row['user'],
                'role' => $row['role'], 'joined_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function entryOf(Consultation $consultation): string
    {
        // Only a walk-in consultation exists in Phase 5; an appointment consultation has no queue entry to move.
        return $consultation->walkin_queue_entry_id ?? throw ConsultationRefused::invalidTransition($consultation->status->value);
    }

    private function clinicOf(string $locationId): ?string
    {
        $clinic = ClinicLocation::query()->whereKey($locationId)->value('clinic_id');

        return is_string($clinic) ? $clinic : null;
    }

    /**
     * Runs the work in one transaction under a short lock wait. A 55P03 is a retryable 409; a 23505 on the one-IN_PROGRESS index is the
     * database's answer to a race the checks did not see, and means the same as ACTIVE_CONSULTATION.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        try {
            return LockTimeoutTransaction::run((int) config('consultations.lock_timeout_ms'), $work);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'uq_one_in_progress_consultation')) {
                throw ConsultationRefused::activeConsultation();
            }

            throw $e;
        } catch (QueryException $e) {
            throw $e->getCode() === '55P03' ? new ConcurrentConsultationChange : $e;
        }
    }
}
