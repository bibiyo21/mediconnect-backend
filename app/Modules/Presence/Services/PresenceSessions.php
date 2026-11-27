<?php

namespace App\Modules\Presence\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Enums\PresenceReason;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Events\DoctorBreakEnded;
use App\Modules\Presence\Events\DoctorBreakStarted;
use App\Modules\Presence\Events\DoctorCheckedIn;
use App\Modules\Presence\Events\DoctorPresenceUpdated;
use App\Modules\Presence\Exceptions\ActiveConsultationBlocksChange;
use App\Modules\Presence\Exceptions\InvalidPresenceTransition;
use App\Modules\Presence\Exceptions\PresenceChangeInProgress;
use App\Modules\Presence\Jobs\SendBreakReminder;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Shared\Contracts\Clock;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Clinic-location presence sessions (increment 5.3, architecture.md §9). Online sessions (D-28)
 * are not built: D-28 is not owner-approved.
 *
 * Every change runs in one transaction that locks the doctor's open session row first (the
 * phase 5 lock order starts here), checks the move against PresenceTransitions, updates the
 * session, appends a `doctor_presence_events` row with the CLAUDE.md §8.5 fields, and writes the
 * audit record. After commit: the Redis hint is rewritten, broadcasts go out and a delayed break
 * reminder is queued. Two simultaneous check-ins are decided by the partial unique index
 * `uq_one_open_presence_session`: the loser gets 409. A WebSocket or network connection is never
 * proof of attendance, so nothing here reads one.
 */
final class PresenceSessions
{
    public function __construct(
        private readonly CheckInEligibility $eligibility,
        private readonly RecordsAudit $audit,
        private readonly SendsNotifications $notifications,
        private readonly PresenceCache $cache,
        private readonly Clock $clock,
        private readonly ClinicAccess $clinics,
        private readonly ActiveConsultations $consultations,
    ) {}

    /**
     * Starts a session at the location, or returns the existing one when the doctor is already IN there
     * (a repeat is a no-op: `wasRecentlyCreated` is false and nothing is written). Order matters:
     * the assignment is checked before any lock, so "no assignment" is the same answer whether or not the
     * doctor is checked in somewhere else; then the open-session check under lock; then service and time.
     */
    public function checkIn(PresenceActor $actor, string $doctorId, string $locationId, ?PresenceReason $reason): DoctorPresenceSession
    {
        $location = $this->eligibility->assertAssigned($doctorId, $locationId);
        $outerLockWait = $this->currentLockWait();

        try {
            return DB::transaction(function () use ($actor, $doctorId, $locationId, $reason, $location) {
                $this->limitLockWait();
                $open = DoctorPresenceSession::where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')->lockForUpdate()->first();
                if ($open !== null) {
                    return $this->answerOpenSession($open, $actor, $locationId);
                }

                $this->eligibility->assertCanCheckInNow($doctorId, $locationId, $location);

                $session = new DoctorPresenceSession;
                $session->forceFill([
                    'id' => (string) Str::uuid7(), 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $locationId, 'session_kind' => 'IN_PERSON',
                    'current_status' => PresenceStatus::In, 'checked_in_at' => $this->clock->now(), 'checked_in_by' => $actor->user->id,
                ])->save();

                $this->record($session, PresenceStatus::NotCheckedIn, $actor, $reason, null, 'presence.checked_in');
                $this->afterChange($session, $actor);
                DoctorCheckedIn::dispatch($doctorId, $locationId, $session->id, PresenceStatus::In, $session->version);
                DoctorPresenceUpdated::dispatch($doctorId, $locationId, $session->id, PresenceStatus::In, $session->version);

                return $session;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Only the one-open-session index means "a concurrent check-in won"; any other violation is a bug.
            if (! str_contains($e->getMessage(), 'uq_one_open_presence_session')) {
                throw $e;
            }
            $open = DoctorPresenceSession::where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')->first();
            if ($open === null) {
                throw new PresenceChangeInProgress;
            }

            return $this->answerOpenSession($open, $actor, $locationId);
        } catch (QueryException $e) {
            throw $this->lockTimeout($e);
        } finally {
            $this->restoreLockWait($outerLockWait);
        }
    }

    /** A check-in found an open session: the same place and IN is a no-op; anything else is a 409. */
    private function answerOpenSession(DoctorPresenceSession $open, PresenceActor $actor, string $locationId): DoctorPresenceSession
    {
        $openAt = (string) $open->clinic_location_id;

        if ($openAt === $locationId && $open->current_status === PresenceStatus::In) {
            // Not a failure: the retry of a request that already succeeded.
            return $open;
        }

        CheckInEligibility::countFailure('open_session');
        if ($openAt === $locationId) {
            throw InvalidPresenceTransition::alreadyCheckedIn($open->current_status);
        }

        // Elsewhere: only the doctor, or someone with presence rights at that other location, learns where.
        $entitled = ! $actor->isOnBehalf() || $this->clinics->allowsAtLocation($actor->user, 'presence.manage', $openAt);

        throw InvalidPresenceTransition::checkedInElsewhere($entitled ? $openAt : null);
    }

    public function startBreak(PresenceActor $actor, string $doctorId, ?string $locationId, int $expectedMinutes, ?string $scheduledBreakId): DoctorPresenceSession
    {
        return $this->change($actor, $doctorId, $locationId, PresenceStatus::In, PresenceStatus::OnBreak, null, 'presence.break_started', function (DoctorPresenceSession $session) use ($doctorId, $expectedMinutes, $scheduledBreakId): array {
            if ($scheduledBreakId !== null && ! $this->scheduledBreakExists($scheduledBreakId, $doctorId, (string) $session->clinic_location_id)) {
                throw ValidationException::withMessages(['scheduled_break_id' => ['This is not one of your scheduled breaks at this location.']]);
            }
            $end = $this->clock->now()->addMinutes($expectedMinutes);
            $session->current_break_expected_end_at = $end;

            return ['break_kind' => $scheduledBreakId === null ? 'UNSCHEDULED' : 'SCHEDULED', 'expected_minutes' => $expectedMinutes, 'scheduled_break_id' => $scheduledBreakId];
        }, function (DoctorPresenceSession $session) {
            $end = $session->current_break_expected_end_at ?? $this->clock->now();
            DoctorBreakStarted::dispatch($session->doctor_profile_id, (string) $session->clinic_location_id, $session->id, $end, $session->version);
            SendBreakReminder::dispatch($session->id, $end->toIso8601ZuluString())->delay($end)->afterCommit();
        });
    }

    public function endBreak(PresenceActor $actor, string $doctorId, ?string $locationId): DoctorPresenceSession
    {
        return $this->change($actor, $doctorId, $locationId, PresenceStatus::OnBreak, PresenceStatus::In, null, 'presence.break_ended', function (DoctorPresenceSession $session): array {
            $expected = $session->current_break_expected_end_at;
            $overrun = $expected !== null && $this->clock->now()->gt($expected) ? (int) ceil(abs($expected->diffInSeconds($this->clock->now())) / 60) : 0;
            $session->current_break_expected_end_at = null;

            return ['overrun_minutes' => $overrun];
        }, fn (DoctorPresenceSession $s) => DoctorBreakEnded::dispatch($s->doctor_profile_id, (string) $s->clinic_location_id, $s->id, $s->version));
    }

    public function markUnavailable(PresenceActor $actor, string $doctorId, ?string $locationId, ?PresenceReason $reason): DoctorPresenceSession
    {
        return $this->change($actor, $doctorId, $locationId, PresenceStatus::In, PresenceStatus::TemporarilyUnavailable, $reason, 'presence.marked_unavailable');
    }

    public function markAvailable(PresenceActor $actor, string $doctorId, ?string $locationId): DoctorPresenceSession
    {
        return $this->change($actor, $doctorId, $locationId, PresenceStatus::TemporarilyUnavailable, PresenceStatus::In, null, 'presence.marked_available');
    }

    /**
     * @param  (Closure(DoctorPresenceSession): array<string, mixed>)|null  $prepare  validates and sets extra session fields; returns event metadata
     * @param  (Closure(DoctorPresenceSession): void)|null  $broadcast  dispatches the move-specific events (after DoctorPresenceUpdated)
     */
    private function change(PresenceActor $actor, string $doctorId, ?string $locationId, PresenceStatus $expectedFrom, PresenceStatus $to, ?PresenceReason $reason, string $auditAction, ?Closure $prepare = null, ?Closure $broadcast = null): DoctorPresenceSession
    {
        $outerLockWait = $this->currentLockWait();

        try {
            return $this->changeInTransaction($actor, $doctorId, $locationId, $expectedFrom, $to, $reason, $auditAction, $prepare, $broadcast);
        } catch (QueryException $e) {
            throw $this->lockTimeout($e);
        } finally {
            $this->restoreLockWait($outerLockWait);
        }
    }

    /**
     * @param  (Closure(DoctorPresenceSession): array<string, mixed>)|null  $prepare
     * @param  (Closure(DoctorPresenceSession): void)|null  $broadcast
     */
    private function changeInTransaction(PresenceActor $actor, string $doctorId, ?string $locationId, PresenceStatus $expectedFrom, PresenceStatus $to, ?PresenceReason $reason, string $auditAction, ?Closure $prepare, ?Closure $broadcast): DoctorPresenceSession
    {
        return DB::transaction(function () use ($actor, $doctorId, $locationId, $expectedFrom, $to, $reason, $auditAction, $prepare, $broadcast) {
            $this->limitLockWait();
            $session = DoctorPresenceSession::where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')
                ->when($locationId !== null, fn ($q) => $q->where('clinic_location_id', $locationId))->lockForUpdate()->first();
            if ($session === null) {
                throw InvalidPresenceTransition::between(PresenceStatus::NotCheckedIn, $to);
            }

            $from = $session->current_status;
            // Already in the state this call asks for (a retry, a double tap): answer with it, change nothing.
            if ($from === $to) {
                return $session;
            }
            // The endpoint names the one status it moves from (break end is not "available"); the table says the move is legal at all.
            if ($from !== $expectedFrom || ! PresenceTransitions::allows($from, $to)) {
                throw InvalidPresenceTransition::between($from, $to);
            }
            // D-I: taken under the session lock above, so no consultation can start between this check and the change.
            if (in_array($to, [PresenceStatus::OnBreak, PresenceStatus::TemporarilyUnavailable], true) && $this->consultations->inProgressFor($doctorId) !== null) {
                throw new ActiveConsultationBlocksChange;
            }

            $metadata = $prepare !== null ? $prepare($session) : null;
            $session->forceFill(['current_status' => $to, 'version' => $session->version + 1])->save();

            $this->record($session, $from, $actor, $reason, $metadata, $auditAction);
            $this->afterChange($session, $actor);
            DoctorPresenceUpdated::dispatch($doctorId, (string) $session->clinic_location_id, $session->id, $to, $session->version);
            if ($broadcast !== null) {
                $broadcast($session);
            }

            return $session;
        });
    }

    /**
     * The §8.5 event row, and the audit record, in the caller's transaction.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private function record(DoctorPresenceSession $session, PresenceStatus $from, PresenceActor $actor, ?PresenceReason $reason, ?array $metadata, string $auditAction): void
    {
        DB::table('doctor_presence_events')->insert([
            'doctor_presence_session_id' => $session->id,
            'doctor_profile_id' => $session->doctor_profile_id,
            'clinic_location_id' => $session->clinic_location_id,
            'previous_status' => $from->value,
            'new_status' => $session->current_status->value,
            'changed_by' => $actor->user->id,
            'change_method' => $actor->method->value,
            'reason' => $reason?->value,
            'source_device_id' => $actor->deviceId,
            'metadata' => $metadata === null ? null : json_encode($metadata),
        ]);

        $clinicId = DB::table('clinic_locations')->where('id', $session->clinic_location_id)->value('clinic_id');
        $this->audit->record($auditAction, 'doctor_presence_session', $session->id, [
            'from' => $from->value, 'to' => $session->current_status->value, 'method' => $actor->method->value,
            'clinic_location_id' => $session->clinic_location_id, 'on_behalf' => $actor->isOnBehalf(),
        ], clinicId: is_string($clinicId) ? $clinicId : null);
    }

    /** Redis hint after commit, and a notice to the doctor when someone else changed their status. */
    private function afterChange(DoctorPresenceSession $session, PresenceActor $actor): void
    {
        $doctorId = $session->doctor_profile_id;
        $locationId = (string) $session->clinic_location_id;
        $status = $session->current_status;
        $version = $session->version;
        $sessionId = $session->id;
        DB::afterCommit(function () use ($doctorId, $locationId, $sessionId, $status, $version) {
            $this->cache->put($doctorId, $locationId, $sessionId, $status, $version);
            $this->cache->forgetPublic($doctorId);
        });

        // At most one "changed by staff" notice per doctor and location every 5 minutes: staff running a doctor's breaks must not flood them.
        if ($actor->isOnBehalf() && Cache::add("presence:staff-notice:{$doctorId}:{$locationId}", true, 300)) {
            $doctorUser = DB::table('doctor_profiles')->where('id', $doctorId)->value('user_id');
            if (is_string($doctorUser)) {
                $this->notifications->notify($doctorUser, 'presence.changed_by_staff', 'Your clinic status was updated', 'A member of the clinic team updated your status. Open the app to review it.', ['clinic_location_id' => $locationId]);
            }
        }
    }

    /** A change waits briefly for a competing row lock, then answers 409 instead of hanging (SET LOCAL ends with the transaction). */
    private function limitLockWait(): void
    {
        $ms = max(100, min(10000, (int) config('presence.lock_timeout_ms')));
        DB::statement("SET LOCAL lock_timeout = '{$ms}ms'");
    }

    /**
     * Inside someone else's transaction (a test, a caller's unit of work) SET LOCAL would outlive this call, so the
     * value in force is remembered and put back afterwards. Null when there is no outer transaction: the setting
     * then ends with our own.
     */
    private function currentLockWait(): ?string
    {
        return DB::transactionLevel() > 0 ? (string) DB::selectOne("SELECT current_setting('lock_timeout') AS value")?->value : null;
    }

    private function restoreLockWait(?string $previous): void
    {
        if ($previous === null) {
            return;
        }
        try {
            DB::statement("SET LOCAL lock_timeout = '".str_replace("'", '', $previous)."'");
        } catch (\Throwable) {
            // The outer transaction is unusable anyway; nothing to restore.
        }
    }

    /** 55P03 (lock not available) becomes a retryable 409; anything else is a real error. */
    private function lockTimeout(QueryException $e): \Throwable
    {
        return $e->getCode() === '55P03' ? new PresenceChangeInProgress : $e;
    }

    private function scheduledBreakExists(string $breakId, string $doctorId, string $locationId): bool
    {
        return DB::table('doctor_schedule_breaks as b')->join('doctor_schedule_shifts as sh', 'sh.id', '=', 'b.doctor_schedule_shift_id')->join('doctor_schedules as s', 's.id', '=', 'sh.doctor_schedule_id')
            ->where('b.id', $breakId)->where('s.doctor_profile_id', $doctorId)->where('s.status', 'ACTIVE')->where('sh.clinic_location_id', $locationId)->exists();
    }
}
