<?php

namespace App\Modules\Presence\Services;

use App\Modules\Appointments\Contracts\CheckOutAppointments;
use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Consultations\DTOs\UnfinishedConsultation;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\Presence\Enums\PresenceChangeMethod;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Events\DoctorCheckedOut;
use App\Modules\Presence\Events\DoctorPresenceUpdated;
use App\Modules\Presence\Exceptions\ActiveConsultationBlocksChange;
use App\Modules\Presence\Exceptions\InvalidPresenceTransition;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Contracts\CheckOutQueues;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Doctor check-out from a clinic location (increment 5.8, D-A to D-E and D-I). Presence owns the transaction and the first lock: the doctor's
 * open session row, FOR UPDATE. Then the blocks are read (an unfinished consultation, a CALLED entry), the walk-in queues are locked and
 * dispositioned, appointments are flagged (never cancelled), and the session moves to OUT. Every refusal is decided under that lock, so the
 * preview is advisory only.
 *
 * Lock order (brief §3): session FOR UPDATE, then the consultation read (no lock), queues (ascending id), entries (ascending id), cancelled
 * consultations and care relationships (inside the queue apply), appointments (FOR UPDATE, then the rescheduling-task insert), the session
 * update and the event and audit rows. Check-out never takes advisory lock 5504.
 *
 * The queue day and the appointment window follow the ACTOR (5.8 M1). A doctor or staff check-out is a real departure now, so today's queue
 * and today's appointments are used. The SYSTEM actor closes a session that began on an earlier day (or was forgotten), so it works on the
 * session's own local day: today's queues are untouched and no appointment of today is flagged for rescheduling.
 */
final class PresenceCheckOut
{
    public function __construct(
        private readonly PresenceSessions $sessions,
        private readonly ActiveConsultations $consultations,
        private readonly CheckOutQueues $queues,
        private readonly CheckOutAppointments $appointments,
        private readonly ClinicAccess $clinics,
        private readonly RecordsAudit $audit,
        private readonly Clock $clock,
        private readonly SendsNotifications $notifications,
    ) {}

    /**
     * What a check-out would find, without changing anything. Names, phone numbers, patient ids, reasons and notes are never here.
     * Entry ids need walkins.view at the location; a consultation id needs consultations.view in scope (the doctor's own, or a clinic member's),
     * so a SUPER_ADMIN (which has no consultations.view, HAG-29) gets the status only, and a global reader's id read is audited.
     *
     * @return array{
     *     session: array{id: string, status: string, version: int}|null,
     *     blocking: list<array{code: string, reason_code: string}>,
     *     active_consultation: array{id: string|null, status: string}|null,
     *     queues: list<array{queue_id: string, status: string, counts: array{registered: int, waiting: int, called: int, skipped: int, in_consultation: int}, guest_count: int}>,
     *     called_entry_ids: list<string>|null,
     *     live_entry_ids: list<string>|null,
     *     appointments: array{count: int, items: list<array{id: string, starts_at: string}>}
     * }
     */
    public function preview(User $viewer, string $doctorId, ?string $locationId, bool $isDoctor): array
    {
        $session = $this->openSession($doctorId, $locationId);
        $location = $session !== null ? (string) $session->clinic_location_id : $locationId;
        $now = $this->clock->now();

        $blocking = [];
        if ($session === null) {
            $blocking[] = ['code' => 'INVALID_STATE_TRANSITION', 'reason_code' => 'not_checked_in'];
        }

        // Read only when the doctor holds an open session here, as the check-out itself does (5.8 M1): a stranger's consultation elsewhere
        // is never looked at from this location.
        $activeConsultation = null;
        $unfinished = $session !== null ? $this->consultations->unfinishedFor($doctorId) : null;
        if ($unfinished !== null) {
            $blocking[] = ['code' => 'ACTIVE_CONSULTATION', 'reason_code' => $unfinished->status === ConsultationStatus::InProgress ? 'in_progress' : 'interrupted'];
            $activeConsultation = ['id' => $this->revealConsultation($viewer, $isDoctor, $unfinished), 'status' => $unfinished->status->value];
        }

        $queueRows = [];
        $calledIds = null;
        $liveIds = null;
        // Gated on the open session (5.8 C1): a caller with no session at this location learns nothing about its queues or called entries.
        if ($session !== null && $location !== null) {
            $facts = $this->queues->peek($doctorId, $location, $this->localDate($location, $now));
            if ($facts->calledCount() > 0) {
                $blocking[] = ['code' => 'ACTIVE_CONSULTATION', 'reason_code' => 'called'];
            }
            foreach ($facts->queues as $queue) {
                if (! $queue['doctor_queue']) {
                    continue;
                }
                $queueRows[] = [
                    'queue_id' => $queue['id'],
                    'status' => $queue['status'],
                    'counts' => [
                        'registered' => $queue['registered'], 'waiting' => $queue['waiting'], 'called' => $queue['called'],
                        'skipped' => $queue['skipped'], 'in_consultation' => $queue['in_consultation'],
                    ],
                    'guest_count' => $queue['guests'],
                ];
            }
            // The doctor holds walkins.view for her own queues (SD); staff need it at the location (AC or G).
            if ($isDoctor || $this->clinics->allowsAtLocation($viewer, 'walkins.view', $location)) {
                $calledIds = $facts->calledEntryIds();
                $liveIds = $facts->dispositionableIds();
            }
        }

        $appointments = $location !== null ? $this->appointments->remaining($doctorId, $location, $now, $this->endOfLocalDay($location, $now)) : [];

        return [
            'session' => $session === null ? null : ['id' => (string) $session->id, 'status' => $session->current_status->value, 'version' => (int) $session->version],
            'blocking' => $blocking,
            'active_consultation' => $activeConsultation,
            'queues' => $queueRows,
            'called_entry_ids' => $calledIds,
            'live_entry_ids' => $liveIds,
            'appointments' => [
                'count' => count($appointments),
                'items' => array_map(fn ($a) => ['id' => $a->id, 'starts_at' => $a->startsAt->toIso8601ZuluString()], $appointments),
            ],
        ];
    }

    /**
     * Checks the doctor out. The session row is the first lock; a block refuses before anything is changed. The walk-in disposition is required.
     *
     * @return array{session: DoctorPresenceSession, result: array{queues_paused: int, entries_held: int, entries_cancelled: int, appointments_flagged: int, tasks_created: int}}
     */
    public function checkOut(PresenceActor $actor, string $doctorId, ?string $locationId, CheckOutReason $reason, CheckOutDisposition $disposition, bool $guestsInformed, ?string $expectedSessionId = null): array
    {
        // The system never cancels a patient's place (D-D): a SYSTEM actor may only hold entries (5.8 m4).
        if ($actor->user === null && $disposition === CheckOutDisposition::NotifyAndCancel) {
            throw new LogicException('The SYSTEM actor may not cancel walk-in entries: only HOLD_FOR_REASSIGNMENT.');
        }

        return $this->sessions->guarded(function () use ($actor, $doctorId, $locationId, $reason, $disposition, $guestsInformed, $expectedSessionId): array {
            $session = DoctorPresenceSession::query()->where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')
                ->when($locationId !== null, fn ($q) => $q->where('clinic_location_id', $locationId))
                ->lockForUpdate()->first();
            if ($session === null) {
                throw InvalidPresenceTransition::between(PresenceStatus::NotCheckedIn, PresenceStatus::Out);
            }
            // An automatic check-out is for one session (5.8 m7): if that session was closed and a new one opened meanwhile, the new one is not ours.
            if ($expectedSessionId !== null && (string) $session->id !== $expectedSessionId) {
                throw InvalidPresenceTransition::between(PresenceStatus::NotCheckedIn, PresenceStatus::Out);
            }
            $location = (string) $session->clinic_location_id;
            $from = $session->current_status;
            // The move itself is checked against the table, as every other presence change is (the table is the one source of legal moves).
            if (! PresenceTransitions::allows($from, PresenceStatus::Out)) {
                throw InvalidPresenceTransition::between($from, PresenceStatus::Out);
            }

            $unfinished = $this->consultations->unfinishedFor($doctorId);
            if ($unfinished !== null) {
                throw $unfinished->status === ConsultationStatus::InProgress ? ActiveConsultationBlocksChange::inProgress() : ActiveConsultationBlocksChange::interrupted();
            }

            $now = $this->clock->now();
            $anchor = $actor->user === null ? CarbonImmutable::instance($session->checked_in_at) : $now;
            $day = $this->localDate($location, $anchor);
            $facts = $this->queues->lockForCheckOut($doctorId, $location, $day);
            if ($facts->calledCount() > 0) {
                throw ActiveConsultationBlocksChange::called();
            }

            // 5.8-3 S2: a SYSTEM check-out of a session from an earlier local day sends no hold notice. The past-day sweep cancels those
            // entries later and tells the patient once; a "your place is kept" notice now would contradict it.
            $earlierDay = $actor->user === null && $this->localDate($location, $now) !== $this->localDate($location, $anchor);
            $queueResult = $this->queues->apply($facts, $disposition, $actor->user?->id, $guestsInformed, notifyHeld: ! $earlierDay);
            $flags = $this->appointments->flagForCheckOut($doctorId, $location, $now, $this->endOfLocalDay($location, $anchor));

            $counts = [
                'queues_paused' => $queueResult->queuesPaused,
                'entries_held' => $queueResult->entriesHeld,
                'entries_cancelled' => $queueResult->entriesCancelled,
                'appointments_flagged' => $flags['flagged'],
                'tasks_created' => $flags['tasks_created'],
            ];

            $session->forceFill([
                'current_status' => PresenceStatus::Out,
                'version' => $session->version + 1,
                'checked_out_at' => $now,
                'checked_out_by' => $actor->user?->id,
                'checkout_reason' => $reason->value,
                'checked_out_method' => $actor->method->value,
                'current_break_expected_end_at' => null,
            ])->save();

            $metadata = ['disposition' => $disposition->value] + $counts;
            $this->sessions->record($session, $from, $actor, $reason, $metadata, 'presence.checked_out', $metadata);
            $this->sessions->afterChange($session, $actor);
            if ($actor->method === PresenceChangeMethod::System) {
                // 5.8 D-H: the doctor is told the system closed their session. Generic text, no reason, no names.
                $doctorUser = DB::table('doctor_profiles')->where('id', $doctorId)->value('user_id');
                if (is_string($doctorUser)) {
                    $this->notifications->notify($doctorUser, 'presence.auto_checked_out', 'You were checked out automatically', 'Open the app to review your clinic status.', ['clinic_location_id' => $location]);
                }
            }
            DoctorCheckedOut::dispatch($doctorId, $location, (string) $session->id, PresenceStatus::Out, $session->version);
            DoctorPresenceUpdated::dispatch($doctorId, $location, (string) $session->id, PresenceStatus::Out, $session->version);

            return ['session' => $session, 'result' => $counts];
        });
    }

    private function openSession(string $doctorId, ?string $locationId): ?DoctorPresenceSession
    {
        return DoctorPresenceSession::query()->where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')
            ->when($locationId !== null, fn ($q) => $q->where('clinic_location_id', $locationId))
            ->first();
    }

    /**
     * The consultation id, for a reader who may see that consultation: the doctor herself, or a clinic member with consultations.view at the
     * CONSULTATION's own location (5.7 scope, 5.8 M1), not the location of the doctor's session. An ONLINE consultation has no location, so
     * clinic staff get null. A global reader (ADMIN) gets it too, audited as a 5.7 access event against that clinic. SUPER_ADMIN gets null (HAG-29).
     */
    private function revealConsultation(User $viewer, bool $isDoctor, UnfinishedConsultation $unfinished): ?string
    {
        if ($isDoctor) {
            return $unfinished->id;
        }
        $location = $unfinished->clinicLocationId;
        if ($location === null || ! $this->clinics->allowsAtLocation($viewer, 'consultations.view', $location)) {
            return null;
        }
        if (! $this->clinics->memberAllowsAtLocation($viewer, 'consultations.view', $location)) {
            $this->audit->record('consultations.viewed', 'consultation', $unfinished->id, ['via' => 'check_out_preview'], accessEvent: true, clinicId: $this->clinicOf($location));
        }

        return $unfinished->id;
    }

    private function localDate(string $locationId, CarbonImmutable $now): string
    {
        return $now->setTimezone($this->timezoneOf($locationId))->toDateString();
    }

    /** The end of the location's local day, in UTC: a live appointment that starts before it is "today" for a check-out (D-E). */
    private function endOfLocalDay(string $locationId, CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($this->timezoneOf($locationId))->endOfDay()->utc();
    }

    private function timezoneOf(string $locationId): string
    {
        $timezone = DB::table('clinic_locations')->where('id', $locationId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('mediconnect.timezone');
    }

    private function clinicOf(string $locationId): ?string
    {
        $id = DB::table('clinic_locations')->where('id', $locationId)->value('clinic_id');

        return is_string($id) ? $id : null;
    }
}
